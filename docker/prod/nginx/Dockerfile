# Compila o front (Vue) e serve com Nginx, encaminhando /api para o PHP-FPM.
FROM node:22-alpine AS build
WORKDIR /app
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY web/ ./
RUN npm run build

FROM nginx:1.27-alpine
COPY docker/prod/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
