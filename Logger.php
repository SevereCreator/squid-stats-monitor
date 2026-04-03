<?php
// Logger.php — логирование для Squid Stats Monitor
// Репозиторий: https://github.com/SevereCreator/squid-stats-monitor
//
// Пишет в два файла:
//   logs/app.log    — все события
//   logs/errors.log — только WARNING и ERROR
//
// Пример использования:
//   $log = Logger::getInstance();
//   $log->info('Статистика загружена', ['clients' => 12]);
//   $log->error('Файл не найден', ['path' => '/var/log/squid/access.log']);

class Logger {

    // Уровни логирования
    const DEBUG   = 'DEBUG';
    const INFO    = 'INFO';
    const WARNING = 'WARNING';
    const ERROR   = 'ERROR';

    private static ?Logger $instance = null;

    private string $logDir;
    private string $appLog;
    private string $errorLog;
    private int    $maxFileSize;  // байт, после которых файл ротируется
    private int    $maxBackups;   // сколько старых копий держать
    private string $minLevel;     // записи ниже этого уровня игнорируются

    private array $levelOrder = [
        self::DEBUG   => 0,
        self::INFO    => 1,
        self::WARNING => 2,
        self::ERROR   => 3,
    ];

    // Конструктор приватный — используй getInstance()
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

    // Возвращает единственный экземпляр логгера.
    // $logDir   — папка для логов (по умолчанию ./logs)
    // $minLevel — минимальный уровень: DEBUG | INFO | WARNING | ERROR
    public static function getInstance(
        string $logDir   = __DIR__ . '/logs',
        string $minLevel = self::INFO
    ): self {
        if (self::$instance === null) {
            self::$instance = new self($logDir, $minLevel);
        }
        return self::$instance;
    }

    // Подробности для отладки — видны только при minLevel=DEBUG
    public function debug(string $message, array $context = []): void {
        $this->write(self::DEBUG, $message, $context);
    }

    // Штатные события — запросы, кэш, результаты парсинга
    public function info(string $message, array $context = []): void {
        $this->write(self::INFO, $message, $context);
    }

    // Что-то нештатное, но работа продолжается
    public function warning(string $message, array $context = []): void {
        $this->write(self::WARNING, $message, $context);
    }

    // Операция не выполнена
    public function error(string $message, array $context = []): void {
        $this->write(self::ERROR, $message, $context);
    }

    // Записывает строку в лог, пропуская уровни ниже minLevel
    private function write(string $level, string $message, array $context): void {
        if (($this->levelOrder[$level] ?? 0) < ($this->levelOrder[$this->minLevel] ?? 0)) {
            return;
        }

        $line = $this->formatLine($level, $message, $context);

        // Ротируем если надо, потом пишем
        $this->rotateIfNeeded($this->appLog);
        $this->appendLine($this->appLog, $line);

        // WARNING и ERROR дублируем в errors.log
        if ($this->levelOrder[$level] >= $this->levelOrder[self::WARNING]) {
            $this->rotateIfNeeded($this->errorLog);
            $this->appendLine($this->errorLog, $line);
        }
    }

    // Формат строки: [2025-05-21 14:32:07] INFO    | api.php:56 | Запрос получен | {"lines":10000}
    private function formatLine(string $level, string $message, array $context): string {
        $timestamp   = date('Y-m-d H:i:s');
        $levelPadded = str_pad($level, 7);
        $caller      = $this->getCaller();
        $ctx         = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';

        return "[{$timestamp}] {$levelPadded} | {$caller} | {$message}{$ctx}" . PHP_EOL;
    }

    // Определяет файл и строку, откуда вызван логгер
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

    // Создаёт папку для логов и кладёт .htaccess, чтобы браузер не мог их открыть
    private function ensureLogDir(): void {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }

        $htaccess = $this->logDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }
    }

    // Если файл вырос больше maxFileSize — переименовываем в .1, .2 и т.д.
    // Самый старый файл удаляем
    private function rotateIfNeeded(string $filepath): void {
        if (!file_exists($filepath)) {
            return;
        }
        if (filesize($filepath) < $this->maxFileSize) {
            return;
        }

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

    // Пишет строку в файл с блокировкой, чтобы не было гонки при параллельных запросах
    private function appendLine(string $filepath, string $line): void {
        $handle = @fopen($filepath, 'a');
        if (!$handle) {
            error_log('[SquidStatsMonitor/Logger] Cannot open log file: ' . $filepath);
            return;
        }
        flock($handle, LOCK_EX);
        fwrite($handle, $line);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
