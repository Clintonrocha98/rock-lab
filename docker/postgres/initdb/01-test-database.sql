-- Runs once, on the first start of an empty volume. The CI service gets the
-- same database through POSTGRES_DB in .github/workflows/_pest.yml.
CREATE DATABASE test_rock_lab;
