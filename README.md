# MVP приложения для операторов колл-центра

- Laravel backend принимает Telegram updates, хранит чаты/сообщения в MySQL, отправляет outbound-ответы через transactional outbox + Redis queue,
- Vue 3 frontend даёт операторам realtime-интерфейс с назначением чатов, read receipts и admin controls. Web-клиент также упаковывается в Android через Capacitor.

## Статус MVP

Реализованные core-потоки:

- первый admin bootstrap и token-based auth через Laravel Sanctum;
- Telegram inbound webhook с `X-Telegram-Bot-Api-Secret-Token`;
- chat list, messages, assignment/release/close, heartbeat;
- несколько операторов параллельно: owner-only отправка, admin assign/force-release;
- outbound transactional outbox + Redis queue + retry backoff `1/2/5/10/30` минут;
- Laravel Reverb/Echo private channel + polling fallback;
- Swagger/OpenAPI: `/swagger` и `/api/v1/openapi.json`;
- Vue 3/Vite/Pinia frontend;
- Capacitor Android debug build path.

Документация продукта и архитектуры находится в [`docs/`](docs/README.md).

## Требования к окружению

- Git
- Docker и Docker Compose v2
- Node.js 22+ и npm — для локального frontend/Android workflow
- JDK 21+ — требуется Capacitor Android/Gradle
- Android Studio + Android SDK/build tools — для Android build/open
- Для реального Telegram webhook: публичный HTTPS URL или tunnel (`ngrok`, Cloudflare Tunnel)

## Структура проекта

```text
.
├── backend/              # Laravel 12 API, queues, Reverb events, Telegram integration
├── frontend/             # Vue 3 + Vite + Pinia + Capacitor Android wrapper
├── docker/               # Nginx config
├── docs/                 # PRD, requirements, architecture, API/database/testing plans
├── Dockerfile            # PHP runtime image for backend/queue/reverb/scheduler
├── docker-compose.yml    # local MVP stack
└── .env.example          # root release env template
```

## Быстрый запуск через Docker Compose

### 1. Подготовить env-файлы

```bash
cp .env.example .env
cp frontend/.env.example frontend/.env
cp frontend/.env.android.example frontend/.env.android
```

Для локального fake Telegram режима можно оставить значения по умолчанию. Реальные секреты (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `REVERB_APP_SECRET`) не коммитить.

Auth в MVP использует Sanctum Bearer token с ограниченным TTL (`API_TOKEN_TTL_MINUTES`, по умолчанию 480 минут). Web/mobile frontend хранит token только в `sessionStorage`, чтобы не переживать закрытие вкладки/процесса WebView. Production deployment должен исключать third-party scripts и отдавать строгий CSP; переход на httpOnly SameSite cookies остаётся вариантом для более жёсткой security posture.

### 2. Сгенерировать `APP_KEY`

Root `.env` — canonical env source для Docker Compose. Перед первым запуском сгенерируйте Laravel key и вставьте значение в `APP_KEY=`:

```bash
docker compose run --rm --no-deps --build backend php artisan key:generate --show
# Скопировать выведенное base64:... значение в APP_KEY в .env
```

### 3. Собрать и поднять стек

```bash
# После заполнения APP_KEY в .env
docker compose up -d --build
```

Compose использует runtime image и named volume `backend-vendor`, чтобы Composer dependencies из Docker image не маскировались bind mount-ом `./backend` в свежем clone. Стек включает:

| Service | URL/порт | Назначение |
| --- | --- | --- |
| `nginx` | `http://localhost:8000` | Laravel API через PHP-FPM |
| `backend` | internal | Laravel runtime |
| `mysql` | `localhost:3306` | MySQL database |
| `redis` | `localhost:6379` | queue/cache/locks |
| `reverb` | `localhost:8080` | WebSocket server |
| `queue` | internal | outbound queue worker |
| `scheduler` | internal | outbox polling/retry/auto-release scheduler |
| `frontend` | `http://localhost:5173` | Vite dev server |

### 4. Подготовить базу данных

```bash
docker compose exec backend php artisan migrate
```

Если контейнер ещё не поднят, можно выполнить одноразово:

```bash
docker compose run --rm backend php artisan migrate
```

### 5. Открыть приложение

- Frontend: <http://localhost:5173>
- API base URL: <http://localhost:8000/api/v1>
- Swagger UI: <http://localhost:8000/swagger>
- OpenAPI JSON: <http://localhost:8000/api/v1/openapi.json>

После чистой миграции без seed первый зарегистрированный пользователь становится admin. После этого production self-registration считается закрытым: admin создаёт операторов и выдаёт admin-права через admin UI/API.

## Telegram webhook

По умолчанию local compose использует `TELEGRAM_FAKE=true`, чтобы outbound можно было тестировать без реального бота. Для реального Telegram:

1. Заполнить в `.env`/deployment secrets:
   - `TELEGRAM_BOT_TOKEN`
   - `TELEGRAM_WEBHOOK_SECRET`
   - `TELEGRAM_WEBHOOK_URL`
2. Убедиться, что backend доступен по публичному HTTPS URL.
3. Экспортировать значения из `.env` в shell и зарегистрировать webhook:

```bash
set -a
source .env
set +a

curl -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/setWebhook" \
  -H "Content-Type: application/json" \
  -d "{\"url\":\"${TELEGRAM_WEBHOOK_URL}\",\"secret_token\":\"${TELEGRAM_WEBHOOK_SECRET}\"}"
```

Telegram будет передавать secret в заголовке `X-Telegram-Bot-Api-Secret-Token`; backend отклоняет несовпадающие requests с `403`.

Для локального tunnel:

```bash
ngrok http 8000
# или
cloudflared tunnel --url http://localhost:8000
```

После получения публичного URL обновить `TELEGRAM_WEBHOOK_URL` и повторить `setWebhook`.

Для local fixture replay доступен dev endpoint:

```bash
TOKEN="<admin-or-operator-bearer-token>"
curl -X POST http://localhost:8000/api/v1/dev/telegram/updates/simulate \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer ${TOKEN}" \
  -d @fixtures/telegram_text_message.json
```

## Frontend local workflow

```bash
cd frontend
npm install
npm run dev
npm test
npm run build
```

`frontend/.env.example` содержит browser-exposed `VITE_*` переменные. Telegram secrets туда не добавлять.

## Android workflow

```bash
cd frontend
cp .env.android.example .env.android
npm install
npm run android:sync
npm run android:open
```

Debug APK из CLI:

```bash
cd frontend
JAVA_HOME=$(/usr/libexec/java_home -v 21) npm run android:build
```

Если Java 21 установлена как default, достаточно `npm run android:build`. Android emulator использует `10.0.2.2` для доступа к host machine; для физического устройства заменить URL в `.env.android` на HTTPS/LAN endpoint.

Android smoke path: login → chat list → open chat → send message → read receipt/delivery status.

## Проверки перед release / PR

```bash
# Compose contract
docker compose config

# Backend tests. BROADCAST_CONNECTION=null изолирует тесты от live Reverb runtime.
docker compose run --rm -e BROADCAST_CONNECTION=null backend php artisan test

# Frontend tests and production build
cd frontend
npm test
npm run build

# Android packaging
npm run android:sync
JAVA_HOME=$(/usr/libexec/java_home -v 21) npm run android:build
```

Ожидаемые результаты MVP:

- backend test suite passes;
- frontend unit tests pass;
- `vue-tsc`/Vite build passes;
- Capacitor sync succeeds;
- Android debug build succeeds with JDK 21+ and Android SDK.

## Production/release notes

- Использовать HTTPS для frontend/API/WebSocket endpoints.
- Хранить real secrets только в deployment secret store, не в git.
- Сгенерировать production `APP_KEY` и уникальные `REVERB_APP_SECRET`/Telegram secrets.
- Оставить backend stateless: все replicas используют общий MySQL, Redis, queue и Reverb/broadcast broker.
- Можно масштабировать `backend`, `queue` и `scheduler` replicas, сохраняя DB locks/idempotency/outbox semantics.
- Inbound Telegram webhook остаётся idempotent через `processed_provider_updates`.
- Redis loss не теряет outbound messages, потому что source of truth — MySQL transactional outbox.
- Auto-release timeout: 10 минут inactivity; heartbeat идёт от active owner frontend.

## GitHub delivery notes

Рекомендуемый flow:

1. Создать feature branch: `feature/<short-scope>` или `release/mvp-packaging`.
2. Проверить, что `.env`, `backend/.env`, `frontend/.env`, `frontend/.env.android`, Android `local.properties`, build outputs и APK/AAB не попали в git.
3. Запустить checklist из раздела “Проверки перед release / PR”.
4. Открыть PR с:
   - кратким summary;
   - ссылками на требования/документацию;
   - списком verification commands и результатов;
   - screenshots/video для UI/Android изменений при необходимости;
   - known risks/follow-ups.
5. После merge создать GitHub release/tag, приложить release notes и, если нужно, debug/internal APK artifact из CI или локальной сборки.

Шаблон PR находится в [`.github/PULL_REQUEST_TEMPLATE.md`](.github/PULL_REQUEST_TEMPLATE.md).

## Troubleshooting

- `invalid source release: 21` при Android build: используется JDK 17 или ниже. Установить JDK 21+ и задать `JAVA_HOME`.
- Frontend не получает realtime: проверить `VITE_REVERB_*`, `REVERB_*`, порт `8080`, Bearer token и `/api/v1/broadcasting/auth`.
- Telegram webhook возвращает `403`: проверить `TELEGRAM_WEBHOOK_SECRET` и `secret_token` в `setWebhook`.
- Очередь не отправляет outbound: проверить `queue` service, Redis и записи outbox/deliveries.
- После изменения `backend/composer.lock` пересобрать image и обновить named vendor volume: `docker compose build backend queue scheduler reverb && docker compose run --rm backend composer install`. Если нужен полностью чистый local reset, предварительно выполнить `docker compose down -v` (удалит local DB volume).
