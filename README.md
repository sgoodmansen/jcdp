# Jefferson County Data Portal

Internal PHP/MySQL portal for Jefferson County employee database workflows.

## Local setup with WampServer

1. Copy or keep this folder under your WampServer web root.
2. Create a MySQL database named `jc_data_portal`.
3. Import `database/schema.sql` into that database.
4. Copy `config/config.example.php` to `config/config.php`.
5. Update the database username, password, and `base_url` in `config/config.php`.
6. Open `/jefferson-county-data-portal/public/setup/create-first-admin.php` in your browser.
7. Create the first IT system admin account.
8. Delete or rename the `public/setup` folder after the first admin is created.

## Starter structure

- `public/` contains browser-facing pages and assets.
- `app/` contains shared PHP helpers, authentication, layout, and database access.
- `config/` contains local environment settings.
- `database/` contains MySQL schema files.

## Roles

- `standard_user`: Can access assigned department tools.
- `department_admin`: Can access assigned department tools and future department management features.
- `system_admin`: Can manage the whole portal.

## Department modules

The starter includes three placeholder departments. Each Access database can become its own module without forcing unrelated systems into the same table design.
