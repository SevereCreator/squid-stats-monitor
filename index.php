<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Squid Stats Monitor</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* Анимация пульса для индикатора "live" */
        @keyframes pulse-ring {
            0%   { transform: scale(1);   opacity: 1; }
            100% { transform: scale(1.6); opacity: 0; }
        }
        .live-dot::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: currentColor;
            animation: pulse-ring 1.4s ease-out infinite;
        }
        .live-dot { position: relative; display: inline-block; }

        /* Плавная анимация строк таблицы */
        #clientsTableBody tr {
            transition: background 0.15s;
        }

        /* Прогресс-бар трафика */
        .traffic-bar {
            transition: width 0.5s ease;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 to-slate-100 min-h-screen">

<div class="min-h-screen p-4 md:p-6">
<div class="max-w-7xl mx-auto space-y-4">

    <!-- ══════════════════════════════════════
         HEADER
    ══════════════════════════════════════ -->
    <div class="bg-white rounded-xl shadow-lg p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex items-center gap-3">
            <i class="fas fa-network-wired text-blue-500 text-3xl"></i>
            <div>
                <h1 class="text-xl font-bold text-slate-800">Squid Stats Monitor</h1>
                <p class="text-sm text-slate-500">Мониторинг прокси-сервера в реальном времени</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <!-- Live-индикатор -->
            <span class="flex items-center gap-2 text-sm text-emerald-600 font-semibold">
                <span class="live-dot w-2.5 h-2.5 bg-emerald-500 rounded-full text-emerald-500"></span>
                LIVE
            </span>

            <!-- Счётчик до обновления -->
            <span class="text-sm text-slate-500">
                Обновление через <span id="countdown" class="font-bold text-blue-600">30</span> с
            </span>

            <!-- Интервал обновления -->
            <select id="intervalSelect"
                    class="text-sm border border-slate-200 rounded-lg px-3 py-1.5 bg-white text-slate-700 focus:outline-none focus:ring-2 focus:ring-blue-300"
                    onchange="changeInterval(this.value)">
                <option value="10">10 сек</option>
                <option value="30" selected>30 сек</option>
                <option value="60">1 мин</option>
                <option value="300">5 мин</option>
            </select>

            <!-- Кнопки -->
            <button onclick="refreshStats()"
                    class="flex items-center gap-2 bg-blue-500 hover:bg-blue-600 text-white text-sm font-semibold px-4 py-1.5 rounded-lg transition">
                <i class="fas fa-sync-alt" id="refreshIcon"></i>
                Обновить
            </button>

            <button onclick="exportCSV()"
                    class="flex items-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-semibold px-4 py-1.5 rounded-lg transition">
                <i class="fas fa-file-csv"></i>
                CSV
            </button>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         СВОДНЫЕ КАРТОЧКИ
    ══════════════════════════════════════ -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow p-5">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Всего запросов</span>
                <i class="fas fa-globe text-blue-400"></i>
            </div>
            <p id="totalRequests" class="text-2xl font-bold text-slate-800">—</p>
        </div>
        <div class="bg-white rounded-xl shadow p-5">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Активных клиентов</span>
                <i class="fas fa-users text-purple-400"></i>
            </div>
            <p id="activeClients" class="text-2xl font-bold text-slate-800">—</p>
        </div>
        <div class="bg-white rounded-xl shadow p-5">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Общий трафик</span>
                <i class="fas fa-hdd text-amber-400"></i>
            </div>
            <p id="totalTraffic" class="text-2xl font-bold text-slate-800">—</p>
        </div>
        <div class="bg-white rounded-xl shadow p-5">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Hit Rate</span>
                <i class="fas fa-bolt text-emerald-400"></i>
            </div>
            <p id="hitRate" class="text-2xl font-bold text-slate-800">—</p>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         ПАНЕЛЬ УПРАВЛЕНИЯ
    ══════════════════════════════════════ -->
    <div class="bg-white rounded-xl shadow p-4 flex flex-col md:flex-row md:items-center gap-3">

        <!-- Поиск -->
        <div class="relative flex-1">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
            <input id="searchInput" type="text" placeholder="Поиск по IP или пользователю..."
                   class="w-full pl-9 pr-4 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"
                   oninput="updateTable()">
        </div>

        <!-- Переключатель вид -->
        <div class="flex rounded-lg overflow-hidden border border-slate-200">
            <button id="btnTable" onclick="switchView('table')"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-semibold bg-blue-500 text-white transition">
                <i class="fas fa-table"></i> Таблица
            </button>
            <button id="btnCharts" onclick="switchView('charts')"
                    class="flex items-center gap-2 px-4 py-2 text-sm font-semibold bg-white text-slate-600 hover:bg-slate-50 transition">
                <i class="fas fa-chart-bar"></i> Графики
            </button>
        </div>

        <!-- Статус обновления -->
        <div id="statusBadge" class="flex items-center gap-2 text-sm text-slate-500">
            <i class="fas fa-clock"></i>
            <span id="lastUpdateTime">—</span>
        </div>
    </div>

    <!-- ══════════════════════════════════════
         ТАБЛИЦА КЛИЕНТОВ
    ══════════════════════════════════════ -->
    <div id="tablePanel">
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-800 text-slate-200">
                        <th class="px-5 py-3 text-left font-semibold">
                            <button onclick="sortTable('ip')" class="flex items-center gap-1 hover:text-white">
                                IP-адрес <i id="sort-ip" class="fas fa-sort text-slate-400 text-xs"></i>
                            </button>
                        </th>
                        <th class="px-5 py-3 text-left font-semibold">
                            <button onclick="sortTable('user')" class="flex items-center gap-1 hover:text-white">
                                Пользователь <i id="sort-user" class="fas fa-sort text-slate-400 text-xs"></i>
                            </button>
                        </th>
                        <th class="px-5 py-3 text-left font-semibold">
                            <button onclick="sortTable('traffic')" class="flex items-center gap-1 hover:text-white">
                                Трафик <i id="sort-traffic" class="fas fa-sort-down text-blue-300 text-xs"></i>
                            </button>
                        </th>
                        <th class="px-5 py-3 text-left font-semibold">
                            <button onclick="sortTable('requests')" class="flex items-center gap-1 hover:text-white">
                                Запросов <i id="sort-requests" class="fas fa-sort text-slate-400 text-xs"></i>
                            </button>
                        </th>
                        <th class="px-5 py-3 text-left font-semibold">
                            <button onclick="sortTable('hits')" class="flex items-center gap-1 hover:text-white">
                                Hit Rate <i id="sort-hits" class="fas fa-sort text-slate-400 text-xs"></i>
                            </button>
                        </th>
                        <th class="px-5 py-3 text-left font-semibold">
                            <button onclick="sortTable('avgTime')" class="flex items-center gap-1 hover:text-white">
                                Avg Time <i id="sort-avgTime" class="fas fa-sort text-slate-400 text-xs"></i>
                            </button>
                        </th>
                        <th class="px-5 py-3 text-left font-semibold">Доля трафика</th>
                    </tr>
                </thead>
                <tbody id="clientsTableBody">
                    <tr>
                        <td colspan="7" class="text-center py-16 text-slate-400">
                            <i class="fas fa-spinner fa-spin text-3xl mb-3 block"></i>
                            Загрузка данных...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p id="rowCount" class="text-xs text-slate-400 mt-2 text-right"></p>
    </div>

    <!-- ══════════════════════════════════════
         ГРАФИКИ
    ══════════════════════════════════════ -->
    <div id="chartsPanel" style="display:none">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-xl shadow p-5">
                <h3 class="text-sm font-semibold text-slate-600 mb-3">
                    <i class="fas fa-chart-bar text-blue-400 mr-2"></i>Топ-10 клиентов по трафику (ГБ)
                </h3>
                <canvas id="barChart"></canvas>
            </div>
            <div class="bg-white rounded-xl shadow p-5">
                <h3 class="text-sm font-semibold text-slate-600 mb-3">
                    <i class="fas fa-chart-pie text-purple-400 mr-2"></i>Распределение трафика
                </h3>
                <canvas id="pieChart"></canvas>
            </div>
        </div>
    </div>

</div><!-- /max-w-7xl -->
</div><!-- /min-h-screen -->

<!-- ══════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════ -->
<script>
'use strict';

// ─── Глобальное состояние ────────────────────────────────────────────────────
let statsData     = null;
let sortConfig    = { key: 'traffic', direction: 'desc' };
let refreshTimer  = null;
let countdownTimer = null;
let refreshInterval = 30; // секунды
let secondsLeft   = 30;
let currentView   = 'table';
let barChartInstance = null;
let pieChartInstance = null;

// ─── Инициализация ───────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadStats();
    startAutoRefresh();
});

// ─── Загрузка данных (AJAX) ──────────────────────────────────────────────────
function loadStats(force = false) {
    const url = force ? 'api.php?refresh=true' : 'api.php';
    console.info(`[SquidStats] loadStats — url: ${url}, force: ${force}`);

    // Анимация кнопки обновить
    document.getElementById('refreshIcon').classList.add('fa-spin');

    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.info('[SquidStats] Данные получены', {
                clients:  data.activeClients,
                requests: data.totalRequests,
                hitRate:  data.totalHitRate,
                cached:   data._meta?.cached,
            });
            statsData = data;
            updateUI();
        })
        .catch(error => {
            console.error('[SquidStats] Ошибка загрузки данных:', error.message);
            showError(error.message);
        })
        .finally(() => {
            document.getElementById('refreshIcon').classList.remove('fa-spin');
        });
}

function refreshStats() {
    console.info('[SquidStats] Принудительное обновление');
    loadStats(true);
    resetCountdown();
}

// ─── Обновление интерфейса ───────────────────────────────────────────────────
function updateUI() {
    if (!statsData) return;
    updateSummaryCards();
    updateTable();
    if (currentView === 'charts') updateCharts();
    document.getElementById('lastUpdateTime').textContent =
        'Обновлено: ' + new Date().toLocaleTimeString('ru-RU');
}

function updateSummaryCards() {
    document.getElementById('totalRequests').textContent =
        formatNumber(statsData.totalRequests);
    document.getElementById('activeClients').textContent =
        statsData.activeClients;
    document.getElementById('totalTraffic').textContent =
        formatBytes(statsData.totalTraffic);
    document.getElementById('hitRate').textContent =
        (statsData.totalHitRate ?? 0).toFixed(1) + '%';
}

// ─── Таблица ─────────────────────────────────────────────────────────────────
function updateTable() {
    const tbody   = document.getElementById('clientsTableBody');
    const clients = getSortedAndFilteredClients();

    if (!statsData) return;

    if (clients.length === 0) {
        tbody.innerHTML = `
            <tr>
              <td colspan="7" class="text-center py-14 text-slate-400">
                <i class="fas fa-search text-2xl mb-3 block"></i>
                Нет клиентов по заданному фильтру
              </td>
            </tr>`;
        document.getElementById('rowCount').textContent = '';
        return;
    }

    const maxTraffic = Math.max(...statsData.clients.map(c => c.traffic), 1);

    tbody.innerHTML = clients.map((client, idx) => {
        const trafficPercent = (client.traffic / maxTraffic * 100).toFixed(1);
        const rowBorder  = getRowBorderClass(client.traffic, maxTraffic);
        const hitBadge   = getHitBadgeClass(client.hits);
        const barColor   = getBarColorClass(client.traffic, maxTraffic);

        return `
        <tr class="border-l-4 ${rowBorder} hover:bg-slate-50 transition border-b border-slate-100">
          <td class="px-5 py-3 font-mono text-slate-800 text-xs">${client.ip}</td>
          <td class="px-5 py-3 text-slate-600">${client.user}</td>
          <td class="px-5 py-3 font-semibold text-slate-800">${formatBytes(client.traffic)}</td>
          <td class="px-5 py-3 text-slate-600">${formatNumber(client.requests)}</td>
          <td class="px-5 py-3">
            <span class="px-2 py-0.5 rounded-full text-xs font-bold ${hitBadge}">
              ${client.hits.toFixed(1)}%
            </span>
          </td>
          <td class="px-5 py-3 text-slate-600">${client.avgTime.toFixed(0)} мс</td>
          <td class="px-5 py-3 w-28">
            <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
              <div class="${barColor} h-2 rounded-full traffic-bar" style="width:${trafficPercent}%"></div>
            </div>
            <span class="text-xs text-slate-400">${trafficPercent}%</span>
          </td>
        </tr>`;
    }).join('');

    document.getElementById('rowCount').textContent =
        `Показано: ${clients.length} из ${statsData.clients.length} клиентов`;
}

// ─── Сортировка и фильтрация ─────────────────────────────────────────────────
function getSortedAndFilteredClients() {
    if (!statsData || !statsData.clients) return [];

    let clients = [...statsData.clients];

    // Фильтрация
    const search = document.getElementById('searchInput').value.toLowerCase().trim();
    if (search) {
        clients = clients.filter(c =>
            c.ip.toLowerCase().includes(search) ||
            c.user.toLowerCase().includes(search)
        );
    }

    // Сортировка
    clients.sort((a, b) => {
        const aVal = a[sortConfig.key];
        const bVal = b[sortConfig.key];
        if (typeof aVal === 'string') {
            return sortConfig.direction === 'asc'
                ? aVal.localeCompare(bVal)
                : bVal.localeCompare(aVal);
        }
        return sortConfig.direction === 'asc' ? aVal - bVal : bVal - aVal;
    });

    return clients;
}

function sortTable(key) {
    if (sortConfig.key === key) {
        sortConfig.direction = sortConfig.direction === 'desc' ? 'asc' : 'desc';
    } else {
        sortConfig.key = key;
        sortConfig.direction = 'desc';
    }
    console.debug(`[SquidStats] Сортировка: ${sortConfig.key} ${sortConfig.direction}`);
    updateSortIcons();
    updateTable();
}

function updateSortIcons() {
    const cols = ['ip', 'user', 'traffic', 'requests', 'hits', 'avgTime'];
    cols.forEach(col => {
        const el = document.getElementById('sort-' + col);
        if (!el) return;
        if (col === sortConfig.key) {
            el.className = sortConfig.direction === 'desc'
                ? 'fas fa-sort-down text-blue-300 text-xs'
                : 'fas fa-sort-up text-blue-300 text-xs';
        } else {
            el.className = 'fas fa-sort text-slate-400 text-xs';
        }
    });
}

// ─── Графики (Chart.js) ──────────────────────────────────────────────────────
function updateCharts() {
    if (!statsData) return;

    const topClients  = getSortedAndFilteredClients().slice(0, 10);
    const labels      = topClients.map(c => c.user !== 'anonymous' ? c.user : c.ip);
    const trafficData = topClients.map(c => +(c.traffic / 1073741824).toFixed(2)); // GB

    const palette = [
        '#3b82f6','#ef4444','#10b981','#f59e0b',
        '#8b5cf6','#ec4899','#14b8a6','#f97316',
        '#6366f1','#84cc16'
    ];

    // Bar Chart
    const barCtx = document.getElementById('barChart').getContext('2d');
    if (barChartInstance) barChartInstance.destroy();
    barChartInstance = new Chart(barCtx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Трафик (ГБ)',
                data: trafficData,
                backgroundColor: palette,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } } }
        }
    });

    // Pie Chart
    const pieCtx = document.getElementById('pieChart').getContext('2d');
    if (pieChartInstance) pieChartInstance.destroy();
    pieChartInstance = new Chart(pieCtx, {
        type: 'pie',
        data: {
            labels,
            datasets: [{ data: trafficData, backgroundColor: palette }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'right', labels: { font: { size: 11 } } }
            }
        }
    });
}

// ─── Переключение вида ────────────────────────────────────────────────────────
function switchView(view) {
    console.info(`[SquidStats] Переключение вида: ${view}`);
    currentView = view;
    const isTable = view === 'table';

    document.getElementById('tablePanel').style.display  = isTable ? '' : 'none';
    document.getElementById('chartsPanel').style.display = isTable ? 'none' : '';

    document.getElementById('btnTable').className  =
        'flex items-center gap-2 px-4 py-2 text-sm font-semibold transition ' +
        (isTable ? 'bg-blue-500 text-white' : 'bg-white text-slate-600 hover:bg-slate-50');
    document.getElementById('btnCharts').className =
        'flex items-center gap-2 px-4 py-2 text-sm font-semibold transition ' +
        (!isTable ? 'bg-blue-500 text-white' : 'bg-white text-slate-600 hover:bg-slate-50');

    if (!isTable) updateCharts();
}

// ─── Автообновление ───────────────────────────────────────────────────────────
function startAutoRefresh() {
    resetCountdown();
}

function changeInterval(value) {
    refreshInterval = parseInt(value);
    console.info(`[SquidStats] Интервал обновления изменён: ${refreshInterval} сек`);
    resetCountdown();
}

function resetCountdown() {
    clearInterval(refreshTimer);
    clearInterval(countdownTimer);
    secondsLeft = refreshInterval;
    document.getElementById('countdown').textContent = secondsLeft;

    countdownTimer = setInterval(() => {
        secondsLeft--;
        document.getElementById('countdown').textContent = secondsLeft;
        if (secondsLeft <= 0) {
            secondsLeft = refreshInterval;
            loadStats();
        }
    }, 1000);
}

// ─── Экспорт в CSV ────────────────────────────────────────────────────────────
function exportCSV() {
    if (!statsData || !statsData.clients.length) {
        console.warn('[SquidStats] Экспорт CSV: нет данных');
        alert('Нет данных для экспорта');
        return;
    }

    const BOM   = '\uFEFF';
    const headers = ['IP-адрес', 'Пользователь', 'Трафик (байт)', 'Запросов', 'Hit Rate (%)', 'Avg Time (мс)'];
    const rows  = getSortedAndFilteredClients().map(c =>
        [c.ip, c.user, c.traffic, c.requests, c.hits.toFixed(2), c.avgTime.toFixed(0)].join(';')
    );

    console.info(`[SquidStats] Экспорт CSV: ${rows.length} строк`);
    const csv  = BOM + [headers.join(';'), ...rows].join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href  = URL.createObjectURL(blob);
    link.download = `squid-stats-${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
}

// ─── Вспомогательные функции ──────────────────────────────────────────────────
function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k     = 1024;
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i     = Math.floor(Math.log(bytes) / Math.log(k));
    return (bytes / Math.pow(k, i)).toFixed(2) + ' ' + units[i];
}

function formatNumber(n) {
    return new Intl.NumberFormat('ru-RU').format(n);
}

function getRowBorderClass(traffic, max) {
    const ratio = traffic / max;
    if (ratio > 0.7) return 'border-red-400';
    if (ratio > 0.4) return 'border-amber-400';
    if (ratio > 0.1) return 'border-blue-300';
    return 'border-slate-200';
}

function getBarColorClass(traffic, max) {
    const ratio = traffic / max;
    if (ratio > 0.7) return 'bg-red-500';
    if (ratio > 0.4) return 'bg-amber-500';
    if (ratio > 0.1) return 'bg-blue-500';
    return 'bg-slate-300';
}

function getHitBadgeClass(hitRate) {
    if (hitRate >= 70) return 'bg-emerald-100 text-emerald-700';
    if (hitRate >= 40) return 'bg-amber-100  text-amber-700';
    return 'bg-red-100 text-red-700';
}

function showError(message) {
    document.getElementById('clientsTableBody').innerHTML = `
        <tr>
          <td colspan="7" class="text-center py-16 text-red-500">
            <i class="fas fa-exclamation-triangle text-4xl mb-3 block"></i>
            <p class="font-semibold">Ошибка загрузки данных</p>
            <p class="text-sm text-slate-400 mt-1">${message}</p>
          </td>
        </tr>`;
}
</script>

</body>
</html>
