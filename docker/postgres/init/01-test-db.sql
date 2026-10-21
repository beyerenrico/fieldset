-- Separate database for the automated test suite.
CREATE DATABASE fieldset_test OWNER fieldset;
-- Extra databases so parallel agents/CI shards never share one (DB_DATABASE=… php artisan test).
CREATE DATABASE fieldset_test_b OWNER fieldset;
CREATE DATABASE fieldset_test_c OWNER fieldset;
