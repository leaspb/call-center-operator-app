# MVP-приложение для операторов колл-центра

- бэкенд на PHP/Laravel получает чаты и сообщения из Telegram, сохраняет их в MySQL и отправляет ответы через transactional outbox и очередь в Redis;
- фронтенд на Vue 3 предоставляет операторам интерфейс для работы с чатами в реальном времени, а администратору административные функции;
- web-клиент также упаковывается в Android-приложение через Capacitor.

## Требования к окружению

- Git
- Docker и Docker Compose v2
- Node.js 22+ и npm
- JDK 21+, Android Studio, Android SDK/build tools — для сборки Android-приложения через Gradle
- Публичный HTTPS URL или тоннель (`ngrok` или Cloudflare Tunnel) — для работы с Telegram через webhook в продакшн-режиме.
  Для локального тестирования с реальным Telegram-чатом можно использовать polling-режим без публичного URL.

## Структура проекта

```text
.
├── backend/              # Laravel 12 API, интеграция с Telegram, очереди для гарантированной отправки, realtime-события через Reverb
├── frontend/             # Vue 3 + Vite + Pinia + Android-обёртка Capacitor
├── docker/               # конфигурация Nginx
├── Dockerfile            # PHP runtime-образ для backend/queue/reverb/scheduler
├── docker-compose.yml    # конфигурация Docker Compose
└── .env.example          # пример файла переменных окружения
```

## Быстрый запуск через Docker Compose

### 1. Подготовить env-файлы для бэкенда, фронтенда и мобильного приложения и заполнить их корректными значениями

```bash
cp .env.example .env
cp frontend/.env.example frontend/.env
cp frontend/.env.android.example frontend/.env.android
```

- Корневой `.env` — основной файл конфигурации для Docker Compose: его читает Docker Compose, а backend-контейнеры получают настройки через него же.
- `frontend/.env` содержит доступные в браузере переменные `VITE_*` для локального запуска через `npm run dev`. Секреты бэкенда туда добавлять нельзя.
- `frontend/.env.android` содержит отдельные URL для Android-эмулятора или устройства (`10.0.2.2` вместо `localhost`).

Где генерации APP_KEY можно использовать команду:
```bash
docker compose run --rm --no-deps --build backend php artisan key:generate --show
# Скопировать выведенное base64:... значение в APP_KEY в .env
```

### 2. Собрать и поднять стек приложений

```bash
# После заполнения APP_KEY, TELEGRAM_BOT_TOKEN и остальных параметров в .env
docker compose up -d --build
```

Стек включает:

| Сервис | URL/порт | Назначение |
| --- | --- | --- |
| `nginx` | `http://localhost:8000` | API через PHP-FPM |
| `backend` | внутренний сервис | Laravel runtime |
| `mysql` | `localhost:3306` | база данных MySQL |
| `redis` | `localhost:6379` | очередь, кэш и блокировки |
| `reverb` | `localhost:8080` | WebSocket-сервер |
| `queue` | внутренний сервис | обработчик исходящей очереди |
| `scheduler` | внутренний сервис | планировщик outbox retry/auto-release и Telegram polling каждые 10 секунд при `TELEGRAM_POLLING_ENABLED=true` |
| `frontend` | `http://localhost:5173` | dev-сервер Vite |

### 3. Подготовить базу данных

```bash
docker compose exec backend php artisan migrate
```

### 4. Открыть и протестировать приложения

- фронтенд: <http://localhost:5173>
- базовый URL API: <http://localhost:8000/api/v1>
- Swagger UI: <http://localhost:8000/swagger>
- OpenAPI JSON: <http://localhost:8000/api/v1/openapi.json>

Замечание: После чистой миграции первый зарегистрированный пользователь становится администратором. Администратор может создать операторов в административном интерфейсе.

### Локальное тестирование через polling

1. Создайте бота через BotFather и заполните в `.env`:

```env
TELEGRAM_BOT_TOKEN=<bot-token-from-botfather>
TELEGRAM_POLLING_ENABLED=true
TELEGRAM_POLLING_LIMIT=20
```

2. Если раньше для этого бота был настроен webhook, нужно его удалить. Telegram `getUpdates` не работает одновременно с активным webhook и polling:

```bash
set -a
source .env
set +a

curl -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/deleteWebhook"
```

3. Запустите приложение и миграции:

```bash
docker compose up -d --build
docker compose exec backend php artisan migrate
```

4. Откройте чат с ботом в Telegram и отправьте сообщение. Планировщик будет вызывать `telegram:poll --once` каждые 10 секунд, если `TELEGRAM_POLLING_ENABLED=true`.

5. Откройте фронтенд на <http://localhost:5173>. Входящее сообщение появится в списке чатов, а ответ оператора будет отправлен этому же Telegram-пользователю через outbound outbox и очередь.

### Webhook для production или теста через публичный URL

1. Заполните в `.env` или deployment secrets:
   - `TELEGRAM_BOT_TOKEN`
   - `TELEGRAM_WEBHOOK_SECRET`
   - `TELEGRAM_WEBHOOK_URL`
2. Убедитесь, что бэкенд доступен по публичному HTTPS URL.
3. Экспортируйте значения из `.env` в shell и зарегистрируйте webhook:

```bash
set -a
source .env
set +a

curl -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/setWebhook" \
  -H "Content-Type: application/json" \
  -d "{\"url\":\"${TELEGRAM_WEBHOOK_URL}\",\"secret_token\":\"${TELEGRAM_WEBHOOK_SECRET}\"}"
```

Telegram будет передавать secret в заголовке `X-Telegram-Bot-Api-Secret-Token`; бэкенд отклоняет запросы с несовпадающим secret и возвращает `403`.

Для локального тоннеля:

```bash
ngrok http 8000
# или
cloudflared tunnel --url http://localhost:8000
```

После получения публичного URL обновите `TELEGRAM_WEBHOOK_URL` и повторите `setWebhook`.

Для локального воспроизведения Telegram update без обращения к Telegram также доступен тестовый dev endpoint. Он не заменяет polling, а нужен только для отладки обработки payload:

```bash
TOKEN="<admin-or-operator-bearer-token>"
curl -X POST http://localhost:8000/api/v1/dev/telegram/updates/simulate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -d @fixtures/telegram_text_message.json
```

## Локальный запуск фронтенда

```bash
cd frontend
npm install
npm run dev
npm test
npm run build
```

`frontend/.env.example` содержит доступные в браузере переменные `VITE_*`. Секреты Telegram туда добавлять нельзя.

## Android-сборка

```bash
cd frontend
cp .env.android.example .env.android
npm install
npm run android:sync
npm run android:open
```

`npm run android:sync` дополнительно фиксирует Android Gradle Plugin на `8.12.1`, чтобы проект открывался в Android Studio, где `8.13.0` ещё не поддерживается.

Debug APK из CLI:

```bash
cd frontend
JAVA_HOME=$(/usr/libexec/java_home -v 21) npm run android:build
```

Если Java 21 установлена как версия по умолчанию, достаточно выполнить `npm run android:build`. Android-эмулятор использует `10.0.2.2` для доступа к хост-машине; для физического устройства замените URL в `.env.android` на HTTPS-адрес API или адрес API в локальной сети.

Smoke-проверка Android: вход → список чатов → открытие чата → отправка сообщения → отметка о прочтении / статус доставки.

## Проверки перед релизом / pull request

```bash
# Проверка конфигурации Compose
docker compose config

# Тесты бэкенда. BROADCAST_CONNECTION=null изолирует тесты от запущенного Reverb.
docker compose run --rm -e BROADCAST_CONNECTION=null backend php artisan test

# Тесты фронтенда и production-сборка
cd frontend
npm test
npm run build

# Сборка Android-пакета
npm run android:sync
JAVA_HOME=$(/usr/libexec/java_home -v 21) npm run android:build
```

## Примечания для production и релиза

- Используйте HTTPS для фронтенда, API и WebSocket-endpoint-ов.
- Храните реальные секреты только в хранилище секретов окружения, не в git.
- Сгенерируйте отдельный production `APP_KEY` и уникальные `REVERB_APP_SECRET`/Telegram-секреты.
- Входящий Telegram webhook остаётся идемпотентным через `processed_provider_updates`.
- Потеря Redis не приводит к потере исходящих сообщений, потому что источник истины — MySQL transactional outbox.

## Возможные проблемы

- `invalid source release: 21` при Android-сборке: используется JDK 17 или ниже. Установите JDK 21+ и задайте `JAVA_HOME`.
- Фронтенд не получает realtime-обновлений: проверьте `VITE_REVERB_*`, `REVERB_*`, порт `8080`, Bearer token и `/api/v1/broadcasting/auth`.
- Telegram webhook возвращает `403`: проверьте `TELEGRAM_WEBHOOK_SECRET` и `secret_token` в `setWebhook`.
- Очередь не отправляет исходящие сообщения: проверьте сервис `queue`, Redis и записи outbox/deliveries.
- После изменения `backend/composer.lock` пересоберите образ и обновите именованный vendor volume: `docker compose build backend queue scheduler reverb && docker compose run --rm backend composer install`. Если нужен полностью чистый локальный сброс, предварительно выполните `docker compose down -v` — это удалит локальный том БД.
