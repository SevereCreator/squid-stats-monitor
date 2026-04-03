<?php
// api.php — REST API для получения статистики прокси-сервера Squid
// Репозиторий: https://github.com/SevereCreator/squid-stats-monitor
//
// GET-параметры:
//   refresh=true — сбросить кэш и перечитать лог
//   lines=N      — сколько последних строк анализировать (по умолчанию 10000)
//
// Возвращает JSON со статистикой

// HTTP-заголовки
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

require_once __DIR__ . '/SquidStatsParser.php';
require_once __DIR__ . '/Logger.php';

// На локалке реального лога нет — подставляем демо-файл
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
$demoLog     = __DIR__ . '/access.log.demo';
$realLog     = '/var/log/squid/access.log';

$config = [
    'log_path'       => ($isLocalhost && !file_exists($realLog) && file_exists($demoLog))
                            ? $demoLog
                            : $realLog,
    'cache_lifetime' => 60,       // секунды
    'max_lines'      => 10000,    // максимум строк для анализа
];

$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === 'true';
$lines        = isset($_GET['lines']) ? (int)$_GET['lines'] : $config['max_lines'];
$lines        = max(100, min($lines, 500000)); // ограничение: от 100 до 500 000 строк

$log = Logger::getInstance(__DIR__ . '/logs');

$log->info('Запрос API получен', [
    'method'       => $_SERVER['REQUEST_METHOD'],
    'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'forceRefresh' => $forceRefresh,
    'lines'        => $lines,
]);

try {
    // Проверка доступности лог-файла
    if (!file_exists($config['log_path'])) {
        $log->error('Лог-файл Squid не найден', ['path' => $config['log_path']]);
        http_response_code(404);
        echo json_encode([
            'error'     => 'Log file not found',
            'message'   => "File does not exist: {$config['log_path']}",
            'timestamp' => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!is_readable($config['log_path'])) {
        $log->error('Нет прав на чтение лог-файла Squid', ['path' => $config['log_path']]);
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

    $log->debug('Парсер создан, запрашиваем статистику', ['forceRefresh' => $forceRefresh]);

    // Получение статистики (с кэшированием)
    $stats = $parser->getStats($forceRefresh);

    // Если данных нет — возвращаем пустую структуру (не ошибку)
    if (empty($stats['clients'])) {
        $log->warning('Статистика пуста — клиенты не найдены в лог-файле');
        $stats = [
            'totalTraffic'  => 0,
            'totalRequests' => 0,
            'activeClients' => 0,
            'totalHitRate'  => 0,
            'lastUpdate'    => date('c'),
            'clients'       => [],
        ];
    } else {
        $log->info('Статистика успешно получена', [
            'clients'   => $stats['activeClients'] ?? 0,
            'requests'  => $stats['totalRequests'] ?? 0,
            'hitRate'   => $stats['totalHitRate'] ?? 0,
            'fromCache' => !$forceRefresh && !$parser->isExpired(),
        ]);
    }

    // Добавляем мета-информацию
    $stats['_meta'] = [
        'cached'        => !$forceRefresh && !$parser->isExpired(),
        'cacheLifetime' => $config['cache_lifetime'],
        'logFile'       => basename($config['log_path']),
        'phpVersion'    => PHP_VERSION,
        'demoMode'      => ($config['log_path'] === $demoLog),
    ];

    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Логируем ошибку на сервере
    error_log('[SquidStatsMonitor] API Error: ' . $e->getMessage());
    $log->error('Необработанное исключение в API', [
        'exception' => get_class($e),
        'message'   => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
    ]);

    http_response_code(500);
    echo json_encode([
        'error'     => 'Internal Server Error',
        'message'   => $e->getMessage(),
        'timestamp' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
