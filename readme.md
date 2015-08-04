# DBC to SQL converter

Faker is a PHP library that generates fake data for you. Whether you need to bootstrap your database, create good-looking XML documents, fill-in your persistence to stress test it, or anonymize data taken from a production service, Faker is for you.

Faker is heavily inspired by Perl's [Data::Faker](http://search.cpan.org/~jasonk/Data-Faker-0.07/), and by ruby's [Faker](https://rubygems.org/gems/faker).

Faker requires PHP >= 5.3.3.

Небольшой PHP-скрипт для конвертирования DBC файлов клиента MMORPG World of Warcraft в SQL.

# Поддержка форматов данных
* DBC с заголовком WDBC

# Планируется добавить
* DB2 с заголовком WDB2 (клиент 4.х+)
* ADB (WCH2, клиент 4.х+) - кеш данных
* WDB - кеш данных

# Настройка
```sh
composer install
```
* Скопировать DBC/DB2 файлы в папку DBFilesClient
* Настроить подключение к базе в конфиге
* Запустить index.php в браузере или через коммандную строку:
```sh
php /path/to/index.php
```

При импорте будут созданы 1-2 таблицы для каждого файла:
* dbc_* - таблицы с основными данными файлов
* str_* - таблицы с текстовыми данными