-- Dijalankan sekali oleh image MySQL saat volume data pertama kali dibuat.
--
-- Database test dibuat di sini, bukan lewat perintah manual, supaya tidak ada
-- celah waktu ketika `docker compose --profile test run --rm test` menemukan
-- database test belum ada. Akhiran `_test` adalah kontraknya: tests/TestCase.php
-- menolak berjalan di database yang namanya tidak berakhiran itu.
CREATE DATABASE IF NOT EXISTS creative_trees_billing_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Entrypoint bawaan image hanya memberi MYSQL_USER hak atas database aplikasi;
-- hak atas database test harus diberikan terpisah.
GRANT ALL PRIVILEGES ON creative_trees_billing_test.* TO 'ctb_app'@'%';
FLUSH PRIVILEGES;
