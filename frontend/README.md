# Call Center Operator Frontend

Vue 3 + Vite operator workspace for the Laravel backend.

## Local run

```bash
cp frontend/.env.example frontend/.env
cd frontend
npm install
npm run dev
```

Default API URL is `http://localhost:8000/api/v1`. Reverb is optional: the UI subscribes to broadcasts when `VITE_REVERB_*` is configured and always keeps a 12-second polling fallback plus heartbeat for active assignments.

## Verification

```bash
npm test
npm run build
```
