-- .env.testing conecta como "root" sin contraseña a relaticle_testing.
-- Las conexiones desde 127.0.0.1 son "trust" en la imagen oficial de Postgres.
CREATE ROLE root WITH LOGIN SUPERUSER;
CREATE DATABASE relaticle_testing OWNER root;
