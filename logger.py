"""
logger.py — Модуль логирования для веб-приложения статистики Squid
Подключается к любому Flask-приложению одной строкой.
"""

import logging
import logging.handlers
import os
import sys
import time
import functools
from datetime import datetime
from pathlib import Path


# ─────────────────────────────────────────────
# 1. Константы
# ─────────────────────────────────────────────
LOG_DIR = Path(os.getenv("LOG_DIR", "logs"))
LOG_DIR.mkdir(parents=True, exist_ok=True)

LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO").upper()  # DEBUG / INFO / WARNING / ERROR

# Максимальный размер одного лог-файла и сколько хранить старых
MAX_BYTES   = 5 * 1024 * 1024   # 5 МБ
BACKUP_COUNT = 7                 # 7 ротаций (~35 МБ суммарно)


# ─────────────────────────────────────────────
# 2. Форматтеры
# ─────────────────────────────────────────────
CONSOLE_FMT = "%(asctime)s  %(levelname)-8s  %(name)s — %(message)s"
FILE_FMT    = "%(asctime)s | %(levelname)-8s | %(name)s | %(funcName)s:%(lineno)d | %(message)s"
DATE_FMT    = "%Y-%m-%d %H:%M:%S"


# ─────────────────────────────────────────────
# 3. Фабрика логгеров
# ─────────────────────────────────────────────
def get_logger(name: str) -> logging.Logger:
    """
    Возвращает готовый логгер с нужным именем.

    Использование:
        from logger import get_logger
        log = get_logger(__name__)
        log.info("Приложение запущено")
    """
    logger = logging.getLogger(name)

    # Не добавляем обработчики повторно (важно при hot-reload)
    if logger.handlers:
        return logger

    logger.setLevel(LOG_LEVEL)

    # --- Консоль ---
    console_handler = logging.StreamHandler(sys.stdout)
    console_handler.setFormatter(logging.Formatter(CONSOLE_FMT, DATE_FMT))
    console_handler.setLevel(LOG_LEVEL)

    # --- Общий файл (всё) ---
    file_handler = logging.handlers.RotatingFileHandler(
        LOG_DIR / "app.log",
        maxBytes=MAX_BYTES,
        backupCount=BACKUP_COUNT,
        encoding="utf-8",
    )
    file_handler.setFormatter(logging.Formatter(FILE_FMT, DATE_FMT))
    file_handler.setLevel(LOG_LEVEL)

    # --- Файл только для ошибок ---
    error_handler = logging.handlers.RotatingFileHandler(
        LOG_DIR / "errors.log",
        maxBytes=MAX_BYTES,
        backupCount=BACKUP_COUNT,
        encoding="utf-8",
    )
    error_handler.setFormatter(logging.Formatter(FILE_FMT, DATE_FMT))
    error_handler.setLevel(logging.ERROR)

    logger.addHandler(console_handler)
    logger.addHandler(file_handler)
    logger.addHandler(error_handler)

    return logger


# ─────────────────────────────────────────────
# 4. Flask-интеграция
# ─────────────────────────────────────────────
def init_app_logging(app):
    """
    Подключает логирование к Flask-приложению.

    Вызовите один раз в app.py / create_app():
        from logger import init_app_logging
        init_app_logging(app)
    """
    log = get_logger("squid_stats")

    # Перенаправляем встроенный werkzeug-логгер в наш файл
    werkzeug_log = logging.getLogger("werkzeug")
    werkzeug_log.handlers = []
    werkzeug_handler = logging.handlers.RotatingFileHandler(
        LOG_DIR / "access.log",
        maxBytes=MAX_BYTES,
        backupCount=BACKUP_COUNT,
        encoding="utf-8",
    )
    werkzeug_handler.setFormatter(logging.Formatter(FILE_FMT, DATE_FMT))
    werkzeug_log.addHandler(werkzeug_handler)
    werkzeug_log.setLevel(logging.INFO)

    @app.before_request
    def log_request():
        """Логируем каждый входящий запрос."""
        from flask import request, g
        g.start_time = time.time()
        log.info(
            "→ %s %s  |  IP: %s  |  UA: %.60s",
            request.method,
            request.path,
            request.remote_addr,
            request.user_agent.string,
        )

    @app.after_request
    def log_response(response):
        """Логируем ответ с кодом и временем выполнения."""
        from flask import request, g
        duration_ms = (time.time() - g.get("start_time", time.time())) * 1000
        level = logging.WARNING if response.status_code >= 400 else logging.INFO
        log.log(
            level,
            "← %s %s  |  %s  |  %.1f ms",
            request.method,
            request.path,
            response.status,
            duration_ms,
        )
        return response

    @app.errorhandler(Exception)
    def log_exception(exc):
        """Ловим все необработанные исключения."""
        log.exception("💥 Необработанное исключение: %s", exc)
        return {"error": "Внутренняя ошибка сервера"}, 500

    log.info("✅ Логирование инициализировано. Уровень: %s", LOG_LEVEL)
    log.info("📁 Логи пишутся в: %s", LOG_DIR.resolve())
    return log


# ─────────────────────────────────────────────
# 5. Декоратор для функций парсинга Squid
# ─────────────────────────────────────────────
def log_execution(func):
    """
    Декоратор: логирует вызов функции, аргументы и время выполнения.

    Использование:
        @log_execution
        def parse_squid_log(filepath):
            ...
    """
    log = get_logger(func.__module__)

    @functools.wraps(func)
    def wrapper(*args, **kwargs):
        arg_str = ", ".join(
            [repr(a)[:40] for a in args] +
            [f"{k}={repr(v)[:40]}" for k, v in kwargs.items()]
        )
        log.debug("▶ %s(%s)", func.__name__, arg_str)
        t0 = time.time()
        try:
            result = func(*args, **kwargs)
            elapsed = (time.time() - t0) * 1000
            log.debug("✓ %s  завершена за %.1f ms", func.__name__, elapsed)
            return result
        except Exception as exc:
            elapsed = (time.time() - t0) * 1000
            log.error("✗ %s  упала за %.1f ms: %s", func.__name__, elapsed, exc, exc_info=True)
            raise
    return wrapper


# ─────────────────────────────────────────────
# 6. Хелпер для логирования статистики Squid
# ─────────────────────────────────────────────
class SquidStatsLogger:
    """
    Удобный класс для логирования событий парсинга Squid.

    Использование:
        from logger import SquidStatsLogger
        sq_log = SquidStatsLogger()
        sq_log.parsed(rows=1500, filepath="/var/log/squid/access.log")
        sq_log.cache_hit_rate(hits=70, misses=30)
    """

    def __init__(self):
        self._log = get_logger("squid_stats.parser")

    def parsed(self, rows: int, filepath: str):
        self._log.info("📊 Разобрано строк: %d  из файла: %s", rows, filepath)

    def skipped(self, rows: int, reason: str):
        self._log.warning("⚠ Пропущено строк: %d  причина: %s", rows, reason)

    def cache_hit_rate(self, hits: int, misses: int):
        total = hits + misses
        rate = (hits / total * 100) if total else 0
        self._log.info("🎯 Cache hit rate: %.1f%%  (hits=%d, misses=%d)", rate, hits, misses)

    def top_users(self, users: list):
        self._log.info("👥 Топ пользователей: %s", ", ".join(str(u) for u in users[:5]))

    def top_sites(self, sites: list):
        self._log.info("🌐 Топ сайтов: %s", ", ".join(str(s) for s in sites[:5]))

    def parse_error(self, line: str, error: Exception):
        self._log.error("❌ Ошибка парсинга строки [%.80s]: %s", line.strip(), error)
