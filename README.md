# Squid Proxy Statistics Monitor

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP Version](https://img.shields.io/badge/php-%3E%3D7.0-blue.svg)
![Version](https://img.shields.io/badge/version-1.0.0-green.svg)

Современный веб-интерфейс для мониторинга статистики прокси-сервера Squid в реальном времени.

## ✨ Возможности

- 📊 **Реальное время** - Автоматическое обновление данных (3/5/10/30 сек)
- 🎨 **Цветовая индикация** - Визуальная подсветка клиентов по объему трафика
- 🔍 **Поиск и фильтрация** - Быстрый поиск по IP или пользователю
- 📈 **Графики** - Bar chart и Pie chart для визуализации
- ⚡ **Сортировка** - По любому столбцу (трафик, запросы, Hit Rate и т.д.)
- 💾 **Экспорт** - Выгрузка статистики в CSV
- 🎯 **Hit Rate анализ** - Отслеживание эффективности кэширования
- ⚙️ **Кэширование** - Оптимизация производительности (60 сек)

## 🚀 Быстрый старт

### Требования

- PHP >= 7.0
- Apache или Nginx
- Squid Proxy Server
- Доступ к логам Squid

### Установка

#### Быстрая установка (тестирование)
```bash
git clone https://github.com/SevereCreator/squid-stats-monitor.git
cd squid-stats-monitor
php -S 0.0.0.0:8080
```

Откройте браузер: `http://localhost:8080`

#### Установка с Apache
```bash
sudo git clone https://github.com/SevereCreator/squid-stats-monitor.git /var/www/html/squid-monitor
sudo chown -R www-data:www-data /var/www/html/squid-monitor
sudo chmod -R 755 /var/www/html/squid-monitor
sudo usermod -a -G proxy www-data
sudo systemctl restart apache2
```

## ⚙️ Настройка

Отредактируйте `api.php` и укажите путь к логу Squid:
```php
$parser = new SquidStatsParser('/var/log/squid/access.log');
```

## 📊 Метрики

- **Трафик** - Суммарный объем данных в байтах
- **Запросы** - Количество HTTP-запросов
- **Hit Rate** - Процент попаданий в кэш
- **Avg Time** - Среднее время обработки запроса

### Цветовая индикация:

- 🟢 **Зеленый** - менее 1 GB
- 🟡 **Желтый** - 1-3 GB
- 🟠 **Оранжевый** - 3-5 GB
- 🔴 **Красный** - более 5 GB

## 📝 Лицензия

Этот проект распространяется под лицензией MIT.

## 👨‍💻 Автор

Создано с ❤️ для администраторов Squid Proxy
