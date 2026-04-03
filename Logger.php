<?php
/**
 * Logger.php
 * Модуль логирования для Squid Stats Monitor
 *
 * Часть системы: Squid Stats Monitor
 * Репозиторий: https://github.com/SevereCreator/squid-stats-monitor
 *
 * Файлы логов (создаются автоматически):
 *   logs/app.log    — все события (DEBUG, INFO, WARNING, ERROR)
 *   logs/errors.log — только ошибки (WARNING, ERROR)
 *
 * Использование:
 *   $log = Logger::getInstance();
 *   $log->info('Статистика загружена', ['clients' => 12]);
 *   $log->error('Файл не найден', ['path' => '/var/log/squid/access.log']);
 */

class Logger {

    // ─── Уровни логирования ───────────────────────────────────────────────
    const DEBUG   = 'DEBUG';
    const INFO    = 'INFO';
    const WARNING = 'WARNING';
    const ERROR   = 'ERROR';

    // ─── Настройки (можно переопределить через getInstance) ───────────────
    private static ?Logger $instance = null;

    private string $logDir;
    private string $appLog;
    private string $errorLog;
    private int    $maxFileSize;   // байт, после которых файл ротируется
    private int    $maxBackups;    // сколько старых файлов хранить
    private string $minLevel;      // минимальный уровень для записи

    private array $levelOrder = [
        self::DEBUG   => 0,
        self::INFO    => 1,
        self::WARNING => 2,
        self::ERROR   => 3,
    ];

    // ─────────────────────────────────────────────────────────────────────
    // Конструктор / Singleton
    // ─────────────────────────────────────────────────────────────────────

    private function __construct(
        string $logDir     = __DIR__ . '/logs',
        string $minLevel   = self::INFO,
        int    $maxFileMB  = 5,
        int    $maxBackups = 7
    ) {
        $this->logDir      = rtrim($logDir, '/\\');
        $this->minLevel    = $minLevel;
        $this->maxFileSize = $maxFileMB * 1024 * 1024;
        $this->maxBackups  = $maxBackups;
        $this->appLog      = $this->logDir . '/app.log';
        $this->errorLog    = $this->logDir . '/errors.log';

        $this->ensureLogDir();
    }

    /**
     * Получить единственный экземпляр логгера (Singleton).
     *
     * @param string $logDir   Папка для логов (по умолчанию ./logs)
     * @param string $minLevel Минимальный уровень: DEBUG | INFO | WARNING | ERROR
     */
    public static function getInstance(
        string $logDir   = __DIR__ . '/logs',
        string $minLevel = self::INFO
    ): self {
        if (self::$instance === null) {
            self::$instance = new self($logDir, $minLevel);
        }
        return self::$instance;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Публичные методы логирования
    // ─────────────────────────────────────────────────────────────────────

    /** Отладочное сообщение (подробности, видны только при minLevel=DEBUG) */
    public function debug(string $message, array $context = []): void {
        $this->write(self::DEBUG, $message, $context);
    }

    /** Информационное сообщение — штатная работа приложения */
    public function info(string $message, array $context = []): void {
        $this->write(self::INFO, $message, $context);
    }

    /** Предупреждение — работа продолжается, но что-то нештатное */
    public function warning(string $message, array $context = []): void {
        $this->write(self::WARNING, $message, $context);
    }

    /** Ошибка — операция не выполнена */
    public function error(string $message, array $context = []): void {
        $this->write(self::ERROR, $message, $context);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Приватные методы
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Записать строку в лог-файл(ы).
     */
    private function write(string $level, string $message, array $context): void {
        // Пропускаем записи ниже минимального уровня
        if (($this->levelOrder[$level] ?? 0) < ($this->levelOrder[$this->minLevel] ?? 0)) {
            return;
        }

        $line = $this->formatLine($level, $message, $context);

        // Ротация перед записью, если файл вырос
        $this->rotateIfNeeded($this->appLog);
        $this->appendLine($this->appLog, $line);

        // В errors.log пишем только WARNING и ERROR
        if ($this->levelOrder[$level] >= $this->levelOrder[self::WARNING]) {
            $this->rotateIfNeeded($this->errorLog);
            $this->appendLine($this->errorLog, $line);
        }
    }

    /**
     * Форматирование строки лога:
     * [2025-05-21 14:32:07] INFO  | api.php | Запрос получен | {"lines":10000}
     */
    private function formatLine(string $level, string $message, array $context): string {
        $timestamp   = date('Y-m-d H:i:s');
        $levelPadded = str_pad($level, 7);
        $caller      = $this->getCaller();
        $ctx         = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        return "[{$timestamp}] {$levelPadded} | {$caller} | {$message}{$ctx}" . PHP_EOL;
    }

    /**
     * Определить, из какого файла вызван логгер.
     */
    private function getCaller(): string {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        foreach ($trace as $frame) {
            $file = $frame['file'] ?? '';
            if ($file && basename($file) !== 'Logger.php') {
                return basename($file) . ':' . ($frame['line'] ?? '?');
            }
        }
        return 'unknown';
    }

    /**
     * Создать папку для логов, если её нет.
     */
    private function ensureLogDir(): void {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        // Файл .htaccess — запрещает прямой доступ к логам через браузер
        $htaccess = $this->logDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }
    }

    /**
     * Ротация файла: если превышен maxFileSize — переименовать в .1, .2 и т.д.
     */
    private function rotateIfNeeded(string $filepath): void {
        if (!file_exists($filepath)) {
            return;
        }
        if (filesize($filepath) < $this->maxFileSize) {
            return;
        }

        // Сдвигаем старые файлы: .6 → удаляем, .5 → .6, ..., без суффикса → .1
        for ($i = $this->maxBackups - 1; $i >= 1; $i--) {
            $old = "{$filepath}.{$i}";
            $new = "{$filepath}." . ($i + 1);
            if (file_exists($old)) {
                if ($i === $this->maxBackups - 1) {
                    unlink($old);
                } else {
                    rename($old, $new);
                }
            }
        }
        rename($filepath, "{$filepath}.1");
    }

    /**
     * Безопасная запись строки в файл.
     */
    private function appendLine(string $filepath, string $line): void {
        $handle = @fopen($filepath, 'a');
        if (!$handle) {
            // Если не можем открыть файл — пишем в системный error_log
            error_log('[SquidStatsMonitor/Logger] Cannot open log file: ' . $filepath);
            return;
        }
        flock($handle, LOCK_EX);
        fwrite($handle, $line);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
