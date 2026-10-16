-- Separate database for the automated test suite.
CREATE DATABASE form_schema_test OWNER form_schema;
-- Extra databases so parallel agents/CI shards never share one (DB_DATABASE=… php artisan test).
CREATE DATABASE form_schema_test_b OWNER form_schema;
CREATE DATABASE form_schema_test_c OWNER form_schema;
