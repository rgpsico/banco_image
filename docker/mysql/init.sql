-- Banco usado pelos testes automatizados (o de desenvolvimento é criado via MYSQL_DATABASE).
CREATE DATABASE IF NOT EXISTS mediahub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON mediahub_test.* TO 'mediahub'@'%';
FLUSH PRIVILEGES;
