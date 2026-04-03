<?php
/**
 * api.php
 * REST API для получения статистики прокси-сервера Squid
 *
 * Часть системы: Squid Stats Monitor
 * Репозиторий: https://github.com/SevereCreator/squid-stats-monitor
 *
 * Параметры GET:
 *   refresh=true   — принудительно сбросить кэш и перечитать лог
 *   lines=N        — количество последних строк лога для анализа (по умолчанию 10000)
 *
 * Ответ: JSON-объект со статистикой
 */

// ─────────────────────────────────────────────────────────────────────────────
// HTTP-заголовки
// ─────────────────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-cache, must-revalidate');

// Preflight OPTIONS — для CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Подключение зависимостей
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/SquidStatsParser.php';
require_once __DIR__ . '/Logger.php'; // [LOGGING]

// ─────────────────────────────────────────────────────────────────────────────
// Конфигурация
// ─────────────────────────────────────────────────────────────────────────────
$config = [
    'log_path'       => '/var/log/squid/access.log',
    'cache_lifetime' => 60,        // секунды
    'max_lines'      => 10000,     // максимум строк для анализа
];

// ─────────────────────────────────────────────────────────────────────────────
// Параметры запроса
// ─────────────────────────────────────────────────────────────────────────────
$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === 'true';
$lines        = isset($_GET['lines']) ? (int)$_GET['lines'] : $config['max_lines'];
$lines        = max(100, min($lines, 500000)); // ограничение: от 100 до 500 000 строк

// ─────────────────────────────────────────────────────────────────────────────
// Инициализация логгера                                              [LOGGING]
// ─────────────────────────────────────────────────────────────────────────────
$log = Logger::getInstance(__DIR__ . '/logs'); // [LOGGING]

$log->info('Запрос API получен', [ // [LOGGING]
    'method'        => $_SERVER['REQUEST_METHOD'], // [LOGGING]
    'ip'            => $_SERVER['REMOTE_ADDR'] ?? 'unknown', // [LOGGING]
    'forceRefresh'  => $forceRefresh, // [LOGGING]
    'lines'         => $lines, // [LOGGING]
]); // [LOGGING]

// ─────────────────────────────────────────────────────────────────────────────
// Основная логика
// ─────────────────────────────────────────────────────────────────────────────
try {
    // Проверка доступности лог-файла
    if (!file_exists($config['log_path'])) {
        $log->error('Лог-файл Squid не найден', ['path' => $config['log_path']]); // [LOGGING]
        http_response_code(404);
        echo json_encode([
            'error'     => 'Log file not found',
            'message'   => "File does not exist: {$config['log_path']}",
            'timestamp' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!is_readable($config['log_path'])) {
        $log->error('Нет прав на чтение лог-файла Squid', ['path' => $config['log_path']]); // [LOGGING]
        http_response_code(403);
        echo json_encode([
            'error'     => 'Permission denied',
            'message'   => "Cannot read file: {$config['log_path']}",
            'timestamp' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Создание парсера
    $parser = new SquidStatsParser($config['log_path']);
    $parser->setCacheLifetime($config['cache_lifetime']);

    $log->debug('Парсер создан, запрашиваем статистику', ['forceRefresh' => $forceRefresh]); // [LOGGING]

    // Получение статистики (с кэшированием)
    $stats = $parser->getStats($forceRefresh);

    // Если данных нет — возвращаем пустую структуру (не ошибку)
    if (empty($stats['clients'])) {
        $log->warning('Статистика пуста — клиенты не найдены в лог-файле'); // [LOGGING]
        $stats = [
            'totalTraffic'  => 0,
            'totalRequests' => 0,
            'activeClients' => 0,
            'totalHitRate'  => 0,
            'lastUpdate'    => date('c'),
            'clients'       => [],
        ];
    } else {
        $log->info('Статистика успешно получена', [ // [LOGGING]
            'clients'       => $stats['activeClients'] ?? 0, // [LOGGING]
            'requests'      => $stats['totalRequests'] ?? 0, // [LOGGING]
            'hitRate'       => $stats['totalHitRate'] ?? 0, // [LOGGING]
            'fromCache'     => !$forceRefresh && !$parser->isExpired(), // [LOGGING]
        ]); // [LOGGING]
    }

    // Добавляем мета-информацию
    $stats['_meta'] = [
        'cached'        => !$forceRefresh && !$parser->isExpired(),
        'cacheLifetime' => $config['cache_lifetime'],
        'logFile'       => basename($config['log_path']),
        'phpVersion'    => PHP_VERSION,
    ];

    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Логируем ошибку на сервере
    error_log('[SquidStatsMonitor] API Error: ' . $e->getMessage());
    $log->error('Необработанное исключение в API', [ // [LOGGING]
        'exception' => get_class($e), // [LOGGING]
        'message'   => $e->getMessage(), // [LOGGING]
        'file'      => $e->getFile(), // [LOGGING]
        'line'      => $e->getLine(), // [LOGGING]
    ]); // [LOGGING]

    http_response_code(500);
    echo json_encode([
        'error'     => 'Internal Server Error',
        'message'   => $e->getMessage(),
        'timestamp' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
