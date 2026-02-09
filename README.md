# Catering API

## Setup
1. composer install
2. Import database_dump.sql into MySQL
3. Configure DB credentials in config
4. Serve via XAMPP/Apache (public/ as web root or use /public in baseUrl)

## Postman
Import postman_collection.json
Set baseUrl to: http://localhost/web_backend_test_catering_api/public

## Endpoints
GET    /facilities
GET    /facilities/{id}
POST   /facilities
PUT    /facilities/{id}
DELETE /facilities/{id}
GET    /facilities/search?name=&city=&tag=
