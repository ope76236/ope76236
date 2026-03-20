-- phpMyAdmin SQL Dump
-- version 4.9.7
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Мар 20 2026 г., 06:31
-- Версия сервера: 8.0.34-26-beget-1-1
-- Версия PHP: 5.6.40

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `l96746oz_turdoc`
--

-- --------------------------------------------------------

--
-- Структура таблицы `applications`
--
-- Создание: Мар 04 2026 г., 07:53
--

DROP TABLE IF EXISTS `applications`;
CREATE TABLE `applications` (
  `id` int UNSIGNED NOT NULL,
  `app_number` int UNSIGNED DEFAULT NULL,
  `manager_user_id` int UNSIGNED DEFAULT NULL,
  `main_tourist_user_id` int UNSIGNED DEFAULT NULL,
  `customer_tourist_user_id` int UNSIGNED DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `destination` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `country` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `hotel_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `room_category` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `meal_plan` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `flights_outbound` text COLLATE utf8mb4_unicode_ci,
  `flights_return` text COLLATE utf8mb4_unicode_ci,
  `transfers_info` text COLLATE utf8mb4_unicode_ci,
  `insurance_info` text COLLATE utf8mb4_unicode_ci,
  `visa_support_info` text COLLATE utf8mb4_unicode_ci,
  `excursions_info` text COLLATE utf8mb4_unicode_ci,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `adults` int UNSIGNED NOT NULL DEFAULT '0',
  `children` int UNSIGNED NOT NULL DEFAULT '0',
  `operator_id` int UNSIGNED DEFAULT NULL,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'KZT',
  `fx_rate_to_kzt` decimal(14,6) NOT NULL DEFAULT '1.000000',
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `partner_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `operator_price_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `tourist_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tourist_price_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `status` enum('in_work','confirmed','docs_issued','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_work',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `applications`
--

INSERT INTO `applications` (`id`, `app_number`, `manager_user_id`, `main_tourist_user_id`, `customer_tourist_user_id`, `title`, `destination`, `country`, `hotel_name`, `room_category`, `meal_plan`, `flights_outbound`, `flights_return`, `transfers_info`, `insurance_info`, `visa_support_info`, `excursions_info`, `start_date`, `end_date`, `adults`, `children`, `operator_id`, `currency`, `fx_rate_to_kzt`, `total_amount`, `partner_amount`, `operator_price_amount`, `tourist_amount`, `tourist_price_amount`, `status`, `note`, `created_at`, `updated_at`) VALUES
(3, 1261256, 1, 2, 4, 'Заявка №1261256 — Россия, Санкт-Петербург', 'Россия, Санкт-Петербург', 'Россия, Санкт-Петербург', 'Аква', 'стандарт', 'BB - завтраки', 'Петропавловск- Омск VS 162', 'Омск - Петропавловск VS 163', 'Аэропорт-Отель-Аэропорт', 'Номад иншуранс', NULL, NULL, '2026-03-05', '2026-03-06', 2, 0, 3, 'USD', '501.000000', '122222.00', '11111.00', '1200.00', '9999.00', '1500.00', 'docs_issued', '', '2026-03-02 11:47:41', '2026-03-06 05:15:11'),
(7, 1232344, 1, 16, 16, 'Заявка №1232344 — Турция', 'Турция', 'Турция', 'Bonjour Hotel (Нячанг)  4', 'Premier Room With Balcony / DBL', 'BB', 'VJ 68 VIETJET AIR Astana(NQZ) 20:25 -> NHA TRANG(CXR) 06:25 +1 31.05.2026 ECO (Y) (2)', 'VJ 67 VIETJET AIR NHA TRANG(CXR) 12:30 -> Astana(NQZ) 18:55 11.06.2026 ECO (Y) (2)', 'GROUP TRANSFER VIETNAM (AIRPORT-HOTEL) (Transfer) 01.06.2026 - 01.06.2026 (2) \r\nGROUP TRANSFER VIETNAM (HOTEL-AIRPORT) (Transfer) 11.06.2026 - 11.06.2026 (2)', 'Euroins Medical Insurance 40000 USD 30 Franchize, 31.05.2026 - 11.06.2026 (2)', NULL, NULL, '2026-03-04', '2026-04-08', 2, 0, 3, 'EUR', '581.000000', '3000.00', '2900.00', '2900.00', '3000.00', '3000.00', 'confirmed', '', '2026-03-06 08:08:48', '2026-03-11 05:35:24');

-- --------------------------------------------------------

--
-- Структура таблицы `application_documents`
--
-- Создание: Мар 02 2026 г., 15:35
--

DROP TABLE IF EXISTS `application_documents`;
CREATE TABLE `application_documents` (
  `id` int UNSIGNED NOT NULL,
  `application_id` int UNSIGNED NOT NULL,
  `template_id` int UNSIGNED NOT NULL,
  `title_override` varchar(255) NOT NULL DEFAULT '',
  `show_in_manager` tinyint(1) NOT NULL DEFAULT '1',
  `show_in_tourist` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `application_documents`
--

INSERT INTO `application_documents` (`id`, `application_id`, `template_id`, `title_override`, `show_in_manager`, `show_in_tourist`, `sort_order`, `created_at`) VALUES
(2, 3, 1, '', 1, 0, 0, '2026-03-05 05:02:30'),
(5, 1, 1, '', 1, 1, 0, '2026-03-06 02:34:47'),
(6, 7, 1, '', 1, 0, 0, '2026-03-11 05:34:08');

-- --------------------------------------------------------

--
-- Структура таблицы `application_tourists`
--
-- Создание: Мар 02 2026 г., 10:18
--

DROP TABLE IF EXISTS `application_tourists`;
CREATE TABLE `application_tourists` (
  `application_id` int UNSIGNED NOT NULL,
  `tourist_user_id` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `application_tourists`
--

INSERT INTO `application_tourists` (`application_id`, `tourist_user_id`, `created_at`) VALUES
(3, 2, '2026-03-02 11:47:50'),
(3, 4, '2026-03-02 11:47:58'),
(7, 2, '2026-03-06 08:09:42'),
(7, 16, '2026-03-06 08:09:05');

-- --------------------------------------------------------

--
-- Структура таблицы `companies`
--
-- Создание: Мар 02 2026 г., 10:18
--

DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `bin` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `address` text COLLATE utf8mb4_unicode_ci,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `bank_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `bik` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `iban` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `kbe` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `knp` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `director_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `director_basis` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'на основании Устава',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `companies`
--

INSERT INTO `companies` (`id`, `name`, `bin`, `address`, `phone`, `email`, `bank_name`, `bik`, `iban`, `kbe`, `knp`, `director_name`, `director_basis`, `created_at`, `updated_at`) VALUES
(1, 'ИП Кривко', '666666666666', 'Омск тра та та', '78787623476', 'hkbjkhb@mail.ru', 'Каспийский', 'CASPSZ', '90980980980ы9ва', '', '', 'Дмитриченко Вадим Александрович', 'лицензии 123', '2026-03-02 10:44:36', '2026-03-02 10:46:09');

-- --------------------------------------------------------

--
-- Структура таблицы `contracts`
--
-- Создание: Мар 02 2026 г., 10:18
--

DROP TABLE IF EXISTS `contracts`;
CREATE TABLE `contracts` (
  `id` int UNSIGNED NOT NULL,
  `application_id` int UNSIGNED NOT NULL,
  `contract_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `contract_date` date DEFAULT NULL,
  `html` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `contracts`
--

INSERT INTO `contracts` (`id`, `application_id`, `contract_number`, `contract_date`, `html`, `created_at`) VALUES
(9, 3, 'TD-3-20260302', '2026-03-02', '\r\n<!doctype html>\r\n<html lang=\"ru\">\r\n<head>\r\n  <meta charset=\"utf-8\">\r\n  <title>Договор TD-3-20260302</title>\r\n  <style>\r\n    body{ font-family: Arial, sans-serif; color:#0f172a; }\r\n    .wrap{ max-width: 900px; margin: 0 auto; padding: 24px; }\r\n    h1{ font-size:18px; margin:0 0 8px; text-align:center; }\r\n    .meta{ text-align:center; font-size:12px; color:#334155; margin-bottom:18px; }\r\n    .p{ font-size:14px; line-height:1.45; margin: 10px 0; }\r\n    .tbl{ width:100%; border-collapse:collapse; margin: 10px 0; font-size:13px; }\r\n    .tbl td, .tbl th{ border:1px solid #cbd5e1; padding:8px; vertical-align:top; }\r\n    .muted{ color:#475569; }\r\n    .sign{ display:grid; grid-template-columns: 1fr 1fr; gap:18px; margin-top:22px; }\r\n    .box{ border:1px solid #cbd5e1; padding:10px; min-height:120px; }\r\n    .small{ font-size:12px; color:#475569; }\r\n    @media print { .noprint{ display:none; } }\r\n  </style>\r\n</head>\r\n<body>\r\n  <div class=\"wrap\">\r\n    <div class=\"noprint\" style=\"margin-bottom:12px;\">\r\n      <button onclick=\"window.print()\">Печать</button>\r\n    </div>\r\n\r\n    <h1>ДОГОВОР на оказание туристских услуг № TD-3-20260302</h1>\r\n    <div class=\"meta\">г. ____________ · дата: 2026-03-02</div>\r\n\r\n    <p class=\"p\">\r\n      ИП Кривко, БИН 666666666666,\r\n      далее «Турагент», в лице Дмитриченко Вадим Александрович (лицензии 123),\r\n      с одной стороны, и\r\n      Дмитриченко Вадим Александрович, ИИН 333333333333,\r\n      далее «Турист», с другой стороны, заключили настоящий договор о нижеследующем:\r\n    </p>\r\n\r\n    <p class=\"p\"><b>1. Предмет договора</b><br>\r\n      Турагент оказывает содействие в бронировании туристского продукта и оформлении документов по туру:\r\n      <b>Германия</b> в период\r\n      <b>2026-03-03</b> — <b>2026-03-05</b>.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>2. Туроператор</b><br>\r\n      Туроператор: <b>ufyjsefsdf</b>.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>3. Стоимость и порядок оплаты</b><br>\r\n      Общая стоимость туристского продукта: <b>122 222.00 KZT</b>.\r\n      Оплата производится в порядке и сроки, согласованные сторонами. Факты оплат фиксируются в CRM/квитанциях.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>4. Условия</b><br>\r\n      Стороны руководствуются законодательством Республики Казахстан. Турист подтверждает достоверность предоставленных данных.\r\n      Условия аннуляции/изменений зависят от правил туроператора и поставщиков услуг.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>5. Данные туриста</b></p>\r\n    <table class=\"tbl\">\r\n      <tr><th>ФИО</th><td>Дмитриченко Вадим Александрович</td></tr>\r\n      <tr><th>ИИН</th><td>333333333333</td></tr>\r\n      <tr><th>Паспорт</th><td>3453345345</td></tr>\r\n      <tr><th>Телефон / Email</th><td>87751462072 / 1turkz@mail.ru</td></tr>\r\n    </table>\r\n\r\n    <p class=\"p\"><b>6. Реквизиты и подписи</b></p>\r\n\r\n    <div class=\"sign\">\r\n      <div class=\"box\">\r\n        <b>Турагент</b><br>\r\n        ИП Кривко<br>\r\n        <span class=\"small\">БИН:</span> 666666666666<br>\r\n        <span class=\"small\">Адрес:</span> Омск тра та та<br>\r\n        <span class=\"small\">Телефон:</span> 78787623476<br>\r\n        <span class=\"small\">Email:</span> hkbjkhb@mail.ru<br><br>\r\n        <span class=\"small\">Банк:</span> Каспийский<br>\r\n        <span class=\"small\">БИК:</span> CASPSZ<br>\r\n        <span class=\"small\">IBAN:</span> 90980980980ы9ва<br>\r\n        <span class=\"small\">КБЕ:</span>  · <span class=\"small\">КНП:</span> <br><br>\r\n        _______________________ / Дмитриченко Вадим Александрович\r\n      </div>\r\n\r\n      <div class=\"box\">\r\n        <b>Турист</b><br>\r\n        Дмитриченко Вадим Александрович<br>\r\n        <span class=\"small\">ИИН:</span> 333333333333<br>\r\n        <span class=\"small\">Паспорт:</span> 3453345345<br>\r\n        <span class=\"small\">Телефон:</span> 87751462072<br>\r\n        <span class=\"small\">Email:</span> 1turkz@mail.ru<br><br>\r\n        _______________________ / Дмитриченко Вадим Александрович\r\n      </div>\r\n    </div>\r\n\r\n    <p class=\"p muted small\" style=\"margin-top:16px;\">\r\n      Примечание: базовый шаблон договора. Далее добавим расширенные условия, приложения и PDF.\r\n    </p>\r\n  </div>\r\n</body>\r\n</html>\r\n', '2026-03-02 11:49:05'),
(10, 3, 'TD-3-20260302', '2026-03-02', '\r\n<!doctype html>\r\n<html lang=\"ru\">\r\n<head>\r\n  <meta charset=\"utf-8\">\r\n  <title>Договор TD-3-20260302</title>\r\n  <style>\r\n    body{ font-family: Arial, sans-serif; color:#0f172a; }\r\n    .wrap{ max-width: 900px; margin: 0 auto; padding: 24px; }\r\n    h1{ font-size:18px; margin:0 0 8px; text-align:center; }\r\n    .meta{ text-align:center; font-size:12px; color:#334155; margin-bottom:18px; }\r\n    .p{ font-size:14px; line-height:1.45; margin: 10px 0; }\r\n    .tbl{ width:100%; border-collapse:collapse; margin: 10px 0; font-size:13px; }\r\n    .tbl td, .tbl th{ border:1px solid #cbd5e1; padding:8px; vertical-align:top; }\r\n    .muted{ color:#475569; }\r\n    .sign{ display:grid; grid-template-columns: 1fr 1fr; gap:18px; margin-top:22px; }\r\n    .box{ border:1px solid #cbd5e1; padding:10px; min-height:120px; }\r\n    .small{ font-size:12px; color:#475569; }\r\n    @media print { .noprint{ display:none; } }\r\n  </style>\r\n</head>\r\n<body>\r\n  <div class=\"wrap\">\r\n    <div class=\"noprint\" style=\"margin-bottom:12px;\">\r\n      <button onclick=\"window.print()\">Печать</button>\r\n    </div>\r\n\r\n    <h1>ДОГОВОР на оказание туристских услуг № TD-3-20260302</h1>\r\n    <div class=\"meta\">г. ____________ · дата: 2026-03-02</div>\r\n\r\n    <p class=\"p\">\r\n      ИП Кривко, БИН 666666666666,\r\n      далее «Турагент», в лице Дмитриченко Вадим Александрович (лицензии 123),\r\n      с одной стороны, и\r\n      Дмитриченко Вадим Александрович, ИИН 333333333333,\r\n      далее «Турист», с другой стороны, заключили настоящий договор о нижеследующем:\r\n    </p>\r\n\r\n    <p class=\"p\"><b>1. Предмет договора</b><br>\r\n      Турагент оказывает содействие в бронировании туристского продукта и оформлении документов по туру:\r\n      <b>Россия</b> в период\r\n      <b>2026-03-03</b> — <b>2026-03-05</b>.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>2. Туроператор</b><br>\r\n      Туроператор: <b>ufyjsefsdf</b>.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>3. Стоимость и порядок оплаты</b><br>\r\n      Общая стоимость туристского продукта: <b>122 222.00 USD</b>.\r\n      Оплата производится в порядке и сроки, согласованные сторонами. Факты оплат фиксируются в CRM/квитанциях.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>4. Условия</b><br>\r\n      Стороны руководствуются законодательством Республики Казахстан. Турист подтверждает достоверность предоставленных данных.\r\n      Условия аннуляции/изменений зависят от правил туроператора и поставщиков услуг.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>5. Данные туриста</b></p>\r\n    <table class=\"tbl\">\r\n      <tr><th>ФИО</th><td>Дмитриченко Вадим Александрович</td></tr>\r\n      <tr><th>ИИН</th><td>333333333333</td></tr>\r\n      <tr><th>Паспорт</th><td>3453345345</td></tr>\r\n      <tr><th>Телефон / Email</th><td>87751462072 / 1turkz@mail.ru</td></tr>\r\n    </table>\r\n\r\n    <p class=\"p\"><b>6. Реквизиты и подписи</b></p>\r\n\r\n    <div class=\"sign\">\r\n      <div class=\"box\">\r\n        <b>Турагент</b><br>\r\n        ИП Кривко<br>\r\n        <span class=\"small\">БИН:</span> 666666666666<br>\r\n        <span class=\"small\">Адрес:</span> Омск тра та та<br>\r\n        <span class=\"small\">Телефон:</span> 78787623476<br>\r\n        <span class=\"small\">Email:</span> hkbjkhb@mail.ru<br><br>\r\n        <span class=\"small\">Банк:</span> Каспийский<br>\r\n        <span class=\"small\">БИК:</span> CASPSZ<br>\r\n        <span class=\"small\">IBAN:</span> 90980980980ы9ва<br>\r\n        <span class=\"small\">КБЕ:</span>  · <span class=\"small\">КНП:</span> <br><br>\r\n        _______________________ / Дмитриченко Вадим Александрович\r\n      </div>\r\n\r\n      <div class=\"box\">\r\n        <b>Турист</b><br>\r\n        Дмитриченко Вадим Александрович<br>\r\n        <span class=\"small\">ИИН:</span> 333333333333<br>\r\n        <span class=\"small\">Паспорт:</span> 3453345345<br>\r\n        <span class=\"small\">Телефон:</span> 87751462072<br>\r\n        <span class=\"small\">Email:</span> 1turkz@mail.ru<br><br>\r\n        _______________________ / Дмитриченко Вадим Александрович\r\n      </div>\r\n    </div>\r\n\r\n    <p class=\"p muted small\" style=\"margin-top:16px;\">\r\n      Примечание: базовый шаблон договора. Далее добавим расширенные условия, приложения и PDF.\r\n    </p>\r\n  </div>\r\n</body>\r\n</html>\r\n', '2026-03-02 15:46:37'),
(11, 3, 'TD-3-20260302', '2026-03-02', '\r\n<!doctype html>\r\n<html lang=\"ru\">\r\n<head>\r\n  <meta charset=\"utf-8\">\r\n  <title>Договор TD-3-20260302</title>\r\n  <style>\r\n    body{ font-family: Arial, sans-serif; color:#0f172a; }\r\n    .wrap{ max-width: 900px; margin: 0 auto; padding: 24px; }\r\n    h1{ font-size:18px; margin:0 0 8px; text-align:center; }\r\n    .meta{ text-align:center; font-size:12px; color:#334155; margin-bottom:18px; }\r\n    .p{ font-size:14px; line-height:1.45; margin: 10px 0; }\r\n    .tbl{ width:100%; border-collapse:collapse; margin: 10px 0; font-size:13px; }\r\n    .tbl td, .tbl th{ border:1px solid #cbd5e1; padding:8px; vertical-align:top; }\r\n    .muted{ color:#475569; }\r\n    .sign{ display:grid; grid-template-columns: 1fr 1fr; gap:18px; margin-top:22px; }\r\n    .box{ border:1px solid #cbd5e1; padding:10px; min-height:120px; }\r\n    .small{ font-size:12px; color:#475569; }\r\n    @media print { .noprint{ display:none; } }\r\n  </style>\r\n</head>\r\n<body>\r\n  <div class=\"wrap\">\r\n    <div class=\"noprint\" style=\"margin-bottom:12px;\">\r\n      <button onclick=\"window.print()\">Печать</button>\r\n    </div>\r\n\r\n    <h1>ДОГОВОР на оказание туристских услуг № TD-3-20260302</h1>\r\n    <div class=\"meta\">г. ____________ · дата: 2026-03-02</div>\r\n\r\n    <p class=\"p\">\r\n      ИП Кривко, БИН 666666666666,\r\n      далее «Турагент», в лице Дмитриченко Вадим Александрович (лицензии 123),\r\n      с одной стороны, и\r\n      Дмитриченко Вадим Александрович, ИИН 333333333333,\r\n      далее «Турист», с другой стороны, заключили настоящий договор о нижеследующем:\r\n    </p>\r\n\r\n    <p class=\"p\"><b>1. Предмет договора</b><br>\r\n      Турагент оказывает содействие в бронировании туристского продукта и оформлении документов по туру:\r\n      <b>Россия</b> в период\r\n      <b>2026-03-03</b> — <b>2026-03-05</b>.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>2. Туроператор</b><br>\r\n      Туроператор: <b>ufyjsefsdf</b>.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>3. Стоимость и порядок оплаты</b><br>\r\n      Общая стоимость туристского продукта: <b>122 222.00 USD</b>.\r\n      Оплата производится в порядке и сроки, согласованные сторонами. Факты оплат фиксируются в CRM/квитанциях.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>4. Условия</b><br>\r\n      Стороны руководствуются законодательством Республики Казахстан. Турист подтверждает достоверность предоставленных данных.\r\n      Условия аннуляции/изменений зависят от правил туроператора и поставщиков услуг.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>5. Данные туриста</b></p>\r\n    <table class=\"tbl\">\r\n      <tr><th>ФИО</th><td>Дмитриченко Вадим Александрович</td></tr>\r\n      <tr><th>ИИН</th><td>333333333333</td></tr>\r\n      <tr><th>Паспорт</th><td>3453345345</td></tr>\r\n      <tr><th>Телефон / Email</th><td>87751462072 / 1turkz@mail.ru</td></tr>\r\n    </table>\r\n\r\n    <p class=\"p\"><b>6. Реквизиты и подписи</b></p>\r\n\r\n    <div class=\"sign\">\r\n      <div class=\"box\">\r\n        <b>Турагент</b><br>\r\n        ИП Кривко<br>\r\n        <span class=\"small\">БИН:</span> 666666666666<br>\r\n        <span class=\"small\">Адрес:</span> Омск тра та та<br>\r\n        <span class=\"small\">Телефон:</span> 78787623476<br>\r\n        <span class=\"small\">Email:</span> hkbjkhb@mail.ru<br><br>\r\n        <span class=\"small\">Банк:</span> Каспийский<br>\r\n        <span class=\"small\">БИК:</span> CASPSZ<br>\r\n        <span class=\"small\">IBAN:</span> 90980980980ы9ва<br>\r\n        <span class=\"small\">КБЕ:</span>  · <span class=\"small\">КНП:</span> <br><br>\r\n        _______________________ / Дмитриченко Вадим Александрович\r\n      </div>\r\n\r\n      <div class=\"box\">\r\n        <b>Турист</b><br>\r\n        Дмитриченко Вадим Александрович<br>\r\n        <span class=\"small\">ИИН:</span> 333333333333<br>\r\n        <span class=\"small\">Паспорт:</span> 3453345345<br>\r\n        <span class=\"small\">Телефон:</span> 87751462072<br>\r\n        <span class=\"small\">Email:</span> 1turkz@mail.ru<br><br>\r\n        _______________________ / Дмитриченко Вадим Александрович\r\n      </div>\r\n    </div>\r\n\r\n    <p class=\"p muted small\" style=\"margin-top:16px;\">\r\n      Примечание: базовый шаблон договора. Далее добавим расширенные условия, приложения и PDF.\r\n    </p>\r\n  </div>\r\n</body>\r\n</html>\r\n', '2026-03-02 16:21:05'),
(12, 3, 'TD-3-20260303', '2026-03-03', '\r\n<!doctype html>\r\n<html lang=\"ru\">\r\n<head>\r\n  <meta charset=\"utf-8\">\r\n  <title>Договор TD-3-20260303</title>\r\n  <style>\r\n    body{ font-family: Arial, sans-serif; color:#0f172a; }\r\n    .wrap{ max-width: 900px; margin: 0 auto; padding: 24px; }\r\n    h1{ font-size:18px; margin:0 0 8px; text-align:center; }\r\n    .meta{ text-align:center; font-size:12px; color:#334155; margin-bottom:18px; }\r\n    .p{ font-size:14px; line-height:1.45; margin: 10px 0; }\r\n    .tbl{ width:100%; border-collapse:collapse; margin: 10px 0; font-size:13px; }\r\n    .tbl td, .tbl th{ border:1px solid #cbd5e1; padding:8px; vertical-align:top; }\r\n    .muted{ color:#475569; }\r\n    .sign{ display:grid; grid-template-columns: 1fr 1fr; gap:18px; margin-top:22px; }\r\n    .box{ border:1px solid #cbd5e1; padding:10px; min-height:120px; }\r\n    .small{ font-size:12px; color:#475569; }\r\n    @media print { .noprint{ display:none; } }\r\n  </style>\r\n</head>\r\n<body>\r\n  <div class=\"wrap\">\r\n    <div class=\"noprint\" style=\"margin-bottom:12px;\">\r\n      <button onclick=\"window.print()\">Печать</button>\r\n    </div>\r\n\r\n    <h1>ДОГОВОР на оказание туристских услуг № TD-3-20260303</h1>\r\n    <div class=\"meta\">г. ____________ · дата: 2026-03-03</div>\r\n\r\n    <p class=\"p\">\r\n      ИП Кривко, БИН 666666666666,\r\n      далее «Турагент», в лице Дмитриченко Вадим Александрович (лицензии 123),\r\n      с одной стороны, и\r\n      Дмитриченко Вадим Александрович, ИИН 333333333333,\r\n      далее «Турист», с другой стороны, заключили настоящий договор о нижеследующем:\r\n    </p>\r\n\r\n    <p class=\"p\"><b>1. Предмет договора</b><br>\r\n      Турагент оказывает содействие в бронировании туристского продукта и оформлении документов по туру:\r\n      <b>Россия</b> в период\r\n      <b>2026-03-03</b> — <b>2026-03-05</b>.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>2. Туроператор</b><br>\r\n      Туроператор: <b>ufyjsefsdf</b>.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>3. Стоимость и порядок оплаты</b><br>\r\n      Общая стоимость туристского продукта: <b>122 222.00 USD</b>.\r\n      Оплата производится в порядке и сроки, согласованные сторонами. Факты оплат фиксируются в CRM/квитанциях.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>4. Условия</b><br>\r\n      Стороны руководствуются законодательством Республики Казахстан. Турист подтверждает достоверность предоставленных данных.\r\n      Условия аннуляции/изменений зависят от правил туроператора и поставщиков услуг.\r\n    </p>\r\n\r\n    <p class=\"p\"><b>5. Данные туриста</b></p>\r\n    <table class=\"tbl\">\r\n      <tr><th>ФИО</th><td>Дмитриченко Вадим Александрович</td></tr>\r\n      <tr><th>ИИН</th><td>333333333333</td></tr>\r\n      <tr><th>Паспорт</th><td>3453345345</td></tr>\r\n      <tr><th>Телефон / Email</th><td>87751462072 / 1turkz@mail.ru</td></tr>\r\n    </table>\r\n\r\n    <p class=\"p\"><b>6. Реквизиты и подписи</b></p>\r\n\r\n    <div class=\"sign\">\r\n      <div class=\"box\">\r\n        <b>Турагент</b><br>\r\n        ИП Кривко<br>\r\n        <span class=\"small\">БИН:</span> 666666666666<br>\r\n        <span class=\"small\">Адрес:</span> Омск тра та та<br>\r\n        <span class=\"small\">Телефон:</span> 78787623476<br>\r\n        <span class=\"small\">Email:</span> hkbjkhb@mail.ru<br><br>\r\n        <span class=\"small\">Банк:</span> Каспийский<br>\r\n        <span class=\"small\">БИК:</span> CASPSZ<br>\r\n        <span class=\"small\">IBAN:</span> 90980980980ы9ва<br>\r\n        <span class=\"small\">КБЕ:</span>  · <span class=\"small\">КНП:</span> <br><br>\r\n        _______________________ / Дмитриченко Вадим Александрович\r\n      </div>\r\n\r\n      <div class=\"box\">\r\n        <b>Турист</b><br>\r\n        Дмитриченко Вадим Александрович<br>\r\n        <span class=\"small\">ИИН:</span> 333333333333<br>\r\n        <span class=\"small\">Паспорт:</span> 3453345345<br>\r\n        <span class=\"small\">Телефон:</span> 87751462072<br>\r\n        <span class=\"small\">Email:</span> 1turkz@mail.ru<br><br>\r\n        _______________________ / Дмитриченко Вадим Александрович\r\n      </div>\r\n    </div>\r\n\r\n    <p class=\"p muted small\" style=\"margin-top:16px;\">\r\n      Примечание: базовый шаблон договора. Далее добавим расширенные условия, приложения и PDF.\r\n    </p>\r\n  </div>\r\n</body>\r\n</html>\r\n', '2026-03-03 00:59:40');

-- --------------------------------------------------------

--
-- Структура таблицы `documents`
--
-- Создание: Мар 02 2026 г., 11:58
--

DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `id` int UNSIGNED NOT NULL,
  `application_id` int UNSIGNED NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file_size` int UNSIGNED NOT NULL DEFAULT '0',
  `doc_type` enum('passport','voucher','ticket','insurance','receipt','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `uploaded_by_role` enum('manager','tourist') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manager',
  `uploaded_by_user_id` int UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `documents`
--

INSERT INTO `documents` (`id`, `application_id`, `file_name`, `stored_name`, `mime_type`, `file_size`, `doc_type`, `note`, `uploaded_by_role`, `uploaded_by_user_id`, `created_at`) VALUES
(3, 3, 'Вопросы для викторины.docx', '6f234ec888faea6bb305c4a2abcae883.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 40991, 'passport', '', 'manager', 1, '2026-03-03 03:43:06'),
(5, 3, 'uds телеграм.png', '3a2234759f8ae7b407864a70104220ea.png', 'image/png', 4978, 'passport', '', 'manager', 1, '2026-03-06 05:19:12'),
(6, 7, 'uds телеграм.png', '264acf557da5ac2088a4b118f0b1f339.png', 'image/png', 4978, 'passport', '', 'manager', 1, '2026-03-06 08:15:45'),
(7, 7, 'Screenshot_20260306_122837_Yandex Start.jpg', '8cc17981700ac6199141f9694ba5b5e5.jpg', 'image/jpeg', 337405, 'passport', '', 'tourist', 16, '2026-03-06 08:16:49');

-- --------------------------------------------------------

--
-- Структура таблицы `document_templates`
--
-- Создание: Мар 02 2026 г., 15:35
--

DROP TABLE IF EXISTS `document_templates`;
CREATE TABLE `document_templates` (
  `id` int UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `body_html` mediumtext NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `show_in_manager` tinyint(1) NOT NULL DEFAULT '1',
  `show_in_tourist` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `document_templates`
--

INSERT INTO `document_templates` (`id`, `title`, `description`, `body_html`, `is_active`, `show_in_manager`, `show_in_tourist`, `created_at`) VALUES
(1, 'Договор с туристом', '', '<!-- Шапка справа (ровно и красиво) -->\r\n<table class=\"no-border\" style=\"width:100%; margin-bottom:10px;\">\r\n  <tbody>\r\n    <tr>\r\n      <td style=\"border:none;\"></td>\r\n      <td style=\"border:none; text-align:right; font-size:11pt; line-height:1.25; white-space:nowrap;\">\r\n        Руководителю<br />\r\n        ИП &laquo;Первое турагентство&raquo;<br />\r\n        ИИН 901013350197<br />\r\n        Республика Казахстан, г. Петропавловск,<br />\r\n        ул. Волочаевская, д. 162<br />\r\n        тел.: +7 (702) 940‑77‑40<br />\r\n        эл. почта: 1turkz@mail.ru\r\n      </td>\r\n    </tr>\r\n  </tbody>\r\n</table>\r\n\r\n<!-- Заголовок по центру -->\r\n<h2 style=\"text-align:center;\">\r\n  Заявление о присоединении к стандартным условиям договора оферты <br /> реализации турпродукта № {app_number} от {application_created_at}\r\n</h2>\r\n\r\n<p>\r\n  Я, {customer_fio}, паспорт № {customer_passport_no}, выдан {customer_passport_issue_date} {customer_passport_issued_by},\r\n  действуя в соответствии со ст. 389 и 395&ndash;397 Гражданского кодекса Республики Казахстан, подтверждаю свое присоединение\r\n  к Стандартным условиям договора о реализации туристского продукта (публичной оферте), опубликованным на сайте\r\n  <a href=\"https://www.1tur.kz\" target=\"_blank\">www.1tur.kz</a>, а также к Типовому договору на туристское обслуживание\r\n  (приказ Министра по инвестициям и развитию РК № 81 от 30.01.2015 г.).\r\n</p>\r\n\r\n<p>\r\n  Подписание настоящего заявления является акцептом Оферты. Заявление, Стандартные условия и Типовой договор составляют единый\r\n  договор о реализации туристского продукта согласно Закону РК &laquo;О туристской деятельности&raquo;.\r\n</p>\r\n\r\n<h3>Сведения о туристах</h3>\r\n\r\n<!-- ВАЖНО: вставляем готовую таблицу целиком, чтобы редактор не ломал <tbody>/<tr> -->\r\n{tourists_table_html}\r\n\r\n<p><strong>Контакт для экстренной связи:</strong> {customer_emergency_contact_name}, тел.: {customer_emergency_contact_phone}</p>\r\n\r\n<h3>Информация о турпродукте</h3>\r\n\r\n<table>\r\n  <tbody>\r\n    <tr>\r\n      <th>Страна / регион</th>\r\n      <th>Даты тура</th>\r\n      <th>Отель</th>\r\n      <th>Питание</th>\r\n      <th>Визовая поддержка</th>\r\n    </tr>\r\n    <tr>\r\n      <td>{app_country}</td>\r\n      <td>{app_start_date} &ndash; {app_end_date}</td>\r\n      <td>{app_hotel}</td>\r\n      <td>{app_meal_plan}</td>\r\n      <td>{trip_visa_support}</td>\r\n    </tr>\r\n  </tbody>\r\n</table>\r\n\r\n<table>\r\n  <tbody>\r\n    <tr>\r\n      <th>Трансфер</th>\r\n      <th>Перевозка</th>\r\n      <th>Страхование</th>\r\n    </tr>\r\n    <tr>\r\n      <td>{trip_transfers}</td>\r\n      <td>{trip_flights}</td>\r\n      <td>{trip_insurance}</td>\r\n    </tr>\r\n  </tbody>\r\n</table>\r\n\r\n<h3>Цена договора</h3>\r\n<table>\r\n  <tbody>\r\n    <tr>\r\n      <th>Стоимость тура (у.е.) / Курс ТО</th>\r\n      <th>Стоимость в тенге</th>\r\n      <th>Предоплата</th>\r\n      <th>Окончательная стоимость</th>\r\n    </tr>\r\n    <tr>\r\n      <td>{app_tourist_price_amount} / {app_fx_rate_to_kzt}</td>\r\n      <td>{price_total_kzt_today}</td>\r\n      <td>________________</td>\r\n      <td>________________</td>\r\n    </tr>\r\n  </tbody>\r\n</table>\r\n\r\n<p><strong>Сроки оплат по договору:</strong></p>\r\n<ol>\r\n  {deadlines_list_html}\r\n</ol>\r\n\r\n<h3>Информация о туроператоре</h3>\r\n<table>\r\n  <tbody>\r\n    <tr>\r\n      <th>Наименование</th>\r\n      <th>Лицензия</th>\r\n      <th>Адрес / Контакты</th>\r\n      <th>Основание сотрудничества</th>\r\n    </tr>\r\n    <tr>\r\n      <td>{operator_full_name}<br />БИН: {operator_bin}</td>\r\n      <td>{operator_license_no} от {operator_license_issue_date}</td>\r\n      <td>{operator_address}<br />тел.: {operator_phone}<br />e-mail: {operator_email}</td>\r\n      <td>Договор № {operator_agency_contract_no} от {operator_agency_contract_date}</td>\r\n    </tr>\r\n  </tbody>\r\n</table>\r\n\r\n<p>\r\n  Я подтверждаю, что все условия договора согласованы и приняты без изменений. Ознакомился с полным текстом договора и приложений\r\n  на сайте турагентства <a href=\"https://www.1tur.kz\" target=\"_blank\">www.1tur.kz</a>.\r\n</p>\r\n\r\n<div class=\"Signature\">\r\n  <table class=\"no-border\">\r\n    <tbody>\r\n      <tr>\r\n        <td>Ф.И.О.: {customer_fio}</td>\r\n        <td>Подпись: ____________________</td>\r\n      </tr>\r\n      <tr>\r\n        <td>Телефон: {emergency_contact_phone}</td>\r\n        <td>E-mail: {емейл заказчика тура}</td>\r\n      </tr>\r\n      <tr>\r\n        <td colspan=\"2\">Адрес: {address}</td>\r\n      </tr>\r\n    </tbody>\r\n  </table>\r\n</div>\r\n\r\n<p>\r\n  Принято &laquo;__&raquo; ___________ 202_ г. ИП &laquo;Первое турагентство&raquo; в лице руководителя Дмитриченко Вадима Александровича,\r\n  на основании уведомления № KZ44UWQ08313171 от 07.02.2026 г. и уведомления о начале туристской деятельности № KZ59UWG00018271 от 19.02.2026 г.\r\n</p>', 1, 1, 1, '2026-03-02 15:46:13');

-- --------------------------------------------------------

--
-- Структура таблицы `operator_fx_rates`
--
-- Создание: Мар 05 2026 г., 03:47
-- Последнее обновление: Мар 20 2026 г., 03:30
--

DROP TABLE IF EXISTS `operator_fx_rates`;
CREATE TABLE `operator_fx_rates` (
  `id` int UNSIGNED NOT NULL,
  `operator_id` int UNSIGNED NOT NULL,
  `currency` varchar(3) NOT NULL,
  `rate_to_kzt` decimal(12,6) NOT NULL DEFAULT '0.000000',
  `source_url` varchar(255) NOT NULL DEFAULT '',
  `captured_by_user_id` int DEFAULT NULL,
  `captured_at` datetime NOT NULL,
  `captured_by_method` varchar(32) NOT NULL DEFAULT 'cron'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `operator_fx_rates`
--

INSERT INTO `operator_fx_rates` (`id`, `operator_id`, `currency`, `rate_to_kzt`, `source_url`, `captured_by_user_id`, `captured_at`, `captured_by_method`) VALUES
(1, 4, 'USD', '490.000000', 'https://b2b.selfietravel.kz/search_tour', NULL, '2026-03-20 06:30:01', 'cron'),
(2, 4, 'EUR', '562.000000', 'https://b2b.selfietravel.kz/search_tour', NULL, '2026-03-20 06:30:01', 'cron'),
(11, 5, 'USD', '491.000000', 'https://online3.anextour.kz/', NULL, '2026-03-20 06:30:02', 'cron'),
(12, 5, 'EUR', '563.000000', 'https://online3.anextour.kz/', NULL, '2026-03-20 06:30:02', 'cron'),
(17, 3, 'USD', '490.000000', 'https://kompastour.com/kz/rus/agentam/currency_arhiv/', NULL, '2026-03-20 06:30:01', 'cron'),
(18, 3, 'EUR', '562.000000', 'https://kompastour.com/kz/rus/agentam/currency_arhiv/', NULL, '2026-03-20 06:30:01', 'cron'),
(29, 7, 'USD', '491.970000', 'https://online.joinup.kz/', NULL, '2026-03-20 06:30:02', 'cron'),
(30, 7, 'EUR', '564.440000', 'https://online.joinup.kz/', NULL, '2026-03-20 06:30:02', 'cron'),
(31, 8, 'USD', '491.000000', 'https://fstravel.asia/', NULL, '2026-03-20 06:30:04', 'cron'),
(32, 8, 'EUR', '563.000000', 'https://fstravel.asia/', NULL, '2026-03-20 06:30:04', 'cron'),
(33, 9, 'USD', '491.000000', 'https://kz.pegast.asia/ExchangeRates', NULL, '2026-03-20 06:30:05', 'cron'),
(34, 9, 'EUR', '563.000000', 'https://kz.pegast.asia/ExchangeRates', NULL, '2026-03-20 06:30:05', 'cron'),
(35, 10, 'USD', '492.000000', 'https://abktourism.kz/', NULL, '2026-03-20 06:30:06', 'cron'),
(36, 10, 'EUR', '566.000000', 'https://abktourism.kz/', NULL, '2026-03-20 06:30:06', 'cron'),
(37, 12, 'USD', '488.000000', 'https://booking-kz.crystalbay.com/search_tour', NULL, '2026-03-20 06:30:06', 'cron'),
(89, 6, 'USD', '490.000000', 'https://online.kazunion.com/currency', NULL, '2026-03-20 06:30:02', 'cron'),
(90, 6, 'EUR', '565.000000', 'https://online.kazunion.com/currency', NULL, '2026-03-20 06:30:02', 'cron'),
(151, 13, 'USD', '489.000000', 'https://online.sanat.kz/TourSearchClient2', NULL, '2026-03-20 06:30:07', 'cron'),
(152, 13, 'EUR', '561.000000', 'https://online.sanat.kz/TourSearchClient2', NULL, '2026-03-20 06:30:07', 'cron'),
(153, 11, 'USD', '510.000000', '', 1, '2026-03-05 06:49:13', 'manual'),
(154, 11, 'EUR', '588.000000', '', 1, '2026-03-05 06:49:26', 'manual');

-- --------------------------------------------------------

--
-- Структура таблицы `operator_links`
--
-- Создание: Мар 05 2026 г., 06:28
--

DROP TABLE IF EXISTS `operator_links`;
CREATE TABLE `operator_links` (
  `id` int NOT NULL,
  `application_id` int NOT NULL,
  `operator_id` int NOT NULL,
  `external_ref` varchar(255) NOT NULL,
  `external_url` text,
  `operator_host` varchar(128) DEFAULT NULL,
  `created_by_user_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Структура таблицы `payments`
--
-- Создание: Мар 03 2026 г., 01:59
--

DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` int UNSIGNED NOT NULL,
  `application_id` int UNSIGNED NOT NULL,
  `direction` enum('tourist_to_agent','agent_to_operator') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payer_type` enum('tourist','partner','operator') COLLATE utf8mb4_unicode_ci NOT NULL,
  `payer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_kzt` decimal(14,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'KZT',
  `fx_rate_to_kzt` decimal(14,6) NOT NULL DEFAULT '1.000000',
  `pay_date` date DEFAULT NULL,
  `status` enum('planned','paid','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planned',
  `method` enum('cash','card','bank','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bank',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `payments`
--

INSERT INTO `payments` (`id`, `application_id`, `direction`, `payer_type`, `payer_name`, `amount`, `amount_kzt`, `currency`, `fx_rate_to_kzt`, `pay_date`, `status`, `method`, `note`, `created_at`) VALUES
(10, 3, 'tourist_to_agent', 'tourist', 'Турист', '10000.00', '0.00', 'KZT', '580.000000', '2026-03-02', 'paid', 'bank', '', '2026-03-03 02:02:07'),
(11, 3, 'tourist_to_agent', 'tourist', 'Турист', '100000.00', '0.00', 'KZT', '545.000000', '2026-03-03', 'paid', 'bank', '', '2026-03-03 02:02:30'),
(14, 3, 'agent_to_operator', 'operator', 'Туроператор', '320000.00', '0.00', 'KZT', '300.000000', '2026-03-02', 'paid', 'bank', '', '2026-03-03 02:07:24'),
(16, 3, 'tourist_to_agent', 'tourist', 'Турист', '778200.00', '0.00', 'KZT', '600.000000', '2026-03-02', 'paid', 'bank', '', '2026-03-04 07:35:53'),
(20, 3, 'agent_to_operator', 'operator', 'Туроператор', '67598.31', '0.00', 'KZT', '507.000000', '2026-03-05', 'paid', 'bank', '', '2026-03-05 01:26:45'),
(23, 3, 'tourist_to_agent', 'tourist', 'Турист', '10000.00', '0.00', 'KZT', '501.000000', '2026-03-06', 'paid', 'bank', '', '2026-03-06 05:07:13'),
(25, 7, 'agent_to_operator', 'operator', 'Туроператор', '1684900.00', '0.00', 'KZT', '581.000000', '2026-03-06', 'paid', 'bank', '', '2026-03-06 08:17:42'),
(26, 7, 'tourist_to_agent', 'tourist', 'Турист', '913332.00', '0.00', 'KZT', '581.000000', '2026-03-06', 'paid', 'bank', '', '2026-03-06 08:18:07'),
(27, 7, 'tourist_to_agent', 'tourist', 'Турист', '829668.00', '0.00', 'KZT', '581.000000', '2026-03-06', 'paid', 'bank', '', '2026-03-06 08:19:26'),
(28, 7, 'tourist_to_agent', 'tourist', 'Турист', '10000.00', '0.00', 'KZT', '581.000000', '2026-03-06', 'paid', 'bank', '', '2026-03-06 08:26:30');

-- --------------------------------------------------------

--
-- Структура таблицы `payment_deadlines`
--
-- Создание: Мар 03 2026 г., 01:39
--

DROP TABLE IF EXISTS `payment_deadlines`;
CREATE TABLE `payment_deadlines` (
  `id` int UNSIGNED NOT NULL,
  `application_id` int UNSIGNED NOT NULL,
  `direction` enum('tourist_to_agent','agent_to_operator') NOT NULL,
  `due_date` date NOT NULL,
  `percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `note` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `payment_deadlines`
--

INSERT INTO `payment_deadlines` (`id`, `application_id`, `direction`, `due_date`, `percent`, `note`, `created_at`) VALUES
(2, 3, 'agent_to_operator', '2026-03-05', '30.00', '', '2026-03-03 01:40:31'),
(4, 3, 'tourist_to_agent', '2026-03-05', '50.00', '', '2026-03-04 09:20:14'),
(5, 3, 'tourist_to_agent', '2026-03-04', '10.00', '', '2026-03-04 17:54:42'),
(6, 3, 'agent_to_operator', '2026-03-06', '20.00', '', '2026-03-05 02:51:37'),
(7, 7, 'tourist_to_agent', '2026-03-07', '30.00', '', '2026-03-06 08:12:26'),
(8, 7, 'tourist_to_agent', '2026-03-08', '70.00', '', '2026-03-06 08:12:38'),
(9, 7, 'agent_to_operator', '2026-03-10', '100.00', '', '2026-03-06 08:12:50');

-- --------------------------------------------------------

--
-- Структура таблицы `tourists`
--
-- Создание: Мар 02 2026 г., 13:37
--

DROP TABLE IF EXISTS `tourists`;
CREATE TABLE `tourists` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `iin` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `last_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `last_name_en` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `first_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `first_name_en` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `middle_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `middle_name_en` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `birth_date` date DEFAULT NULL,
  `passport_no` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `passport_issue_date` date DEFAULT NULL,
  `passport_issued_by` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `passport_expiry_date` date DEFAULT NULL,
  `id_card_no` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `id_card_issue_date` date DEFAULT NULL,
  `id_card_issued_by` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `citizenship` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Казахстан',
  `address` text COLLATE utf8mb4_unicode_ci,
  `emergency_contact_full_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `emergency_contact_phone` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `emergency_contact_relation` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `birth_certificate_no` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `birth_certificate_issue_date` date DEFAULT NULL,
  `birth_certificate_issued_by` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tourists`
--

INSERT INTO `tourists` (`id`, `user_id`, `iin`, `last_name`, `last_name_en`, `first_name`, `first_name_en`, `middle_name`, `middle_name_en`, `birth_date`, `passport_no`, `passport_issue_date`, `passport_issued_by`, `passport_expiry_date`, `id_card_no`, `id_card_issue_date`, `id_card_issued_by`, `citizenship`, `address`, `emergency_contact_full_name`, `emergency_contact_phone`, `emergency_contact_relation`, `birth_certificate_no`, `birth_certificate_issue_date`, `birth_certificate_issued_by`, `created_at`, `updated_at`) VALUES
(1, 2, '901326749836', 'Дмитриченко', 'DMITRICHENKO', 'Вадим', 'VADIM', 'Александрович', 'ALEKSANDROVICH', '1990-10-13', 'N12787634', '2026-03-01', 'МВД РК', '2029-10-17', '', NULL, '', 'Казахстан', 'Петропавловск, Волочаевская 162', 'Дмитриченко Лариса Владимировна', '87752783861', 'Мать', '', NULL, '', '2026-03-02 10:23:03', '2026-03-03 07:17:32'),
(2, 3, '412345678908', 'Петров', '', 'Виктор', '', 'Федорович', '', NULL, '235235235', '2026-01-28', '', '2026-03-10', '', NULL, '', 'Казахстан', '', '', '', '', '', NULL, '', '2026-03-02 10:52:52', '2026-03-02 10:53:24'),
(3, 4, '', 'Федорова', 'FEDOROVA', 'Елена', 'ELENA', 'Викторовна', 'VIKTOROVNA', '1976-10-15', '7223456712', '2025-03-01', 'УМВД России по Омской области', '2032-12-12', '', NULL, '', 'Россия', 'Омск, 3-я Линия 166', 'Кривко Сергей Борисович', '+79502196491', '', '', NULL, '', '2026-03-02 11:06:30', '2026-03-03 07:21:56'),
(6, 7, '', 'Кривко', 'KRIVKO', 'Вадим', 'VADIM', 'Александрович', 'ALEKSANDROVICH', NULL, '', NULL, '', NULL, '', NULL, '', 'Казахстан', '', '', '', '', '', NULL, '', '2026-03-02 12:49:42', '2026-03-02 13:47:28'),
(13, 14, '', 'Кривко', 'KRIVKO', 'Лидия Михайловна', 'LIDIYA MIKHAYLOVNA', '', '', NULL, '', NULL, '', NULL, '', NULL, '', 'Казахстан', NULL, '', '', '', '', NULL, '', '2026-03-06 07:45:29', NULL),
(14, 15, '', 'Дмитриченко', 'DMITRICHENKO', 'Валентина', 'VALENTINA', 'Ивановна', 'IVANOVNA', NULL, '', NULL, '', NULL, '', NULL, '', 'Казахстан', NULL, '', '', '', '', NULL, '', '2026-03-06 07:47:17', NULL),
(15, 16, '', 'Кривко', 'KRIVKO', 'Сергей', 'SERGEY', 'Борисович', 'BORISOVICH', '1979-01-20', '5203196330', '2026-01-09', 'УМВД России по омской области', NULL, '', NULL, '', 'Россия', 'Омск. Серова 8Б', 'Дмитриченко Вадим Александровтич', '23523523', 'муж', '', NULL, '', '2026-03-06 08:00:35', '2026-03-06 08:06:42');

-- --------------------------------------------------------

--
-- Структура таблицы `tour_operators`
--
-- Создание: Мар 03 2026 г., 12:22
-- Последнее обновление: Мар 20 2026 г., 03:30
--

DROP TABLE IF EXISTS `tour_operators`;
CREATE TABLE `tour_operators` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `license_no` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `license_issue_date` date DEFAULT NULL,
  `agency_contract_no` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `agency_contract_date` date DEFAULT NULL,
  `agency_contract_expiry_date` date DEFAULT NULL,
  `bank_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `bank_bik` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `bank_iik` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `bin` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `address` text COLLATE utf8mb4_unicode_ci,
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `fx_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `fx_rate_to_kzt` decimal(12,6) NOT NULL DEFAULT '0.000000',
  `fx_source_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `fx_updated_at` datetime DEFAULT NULL,
  `fx_status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tour_operators`
--

INSERT INTO `tour_operators` (`id`, `name`, `full_name`, `license_no`, `license_issue_date`, `agency_contract_no`, `agency_contract_date`, `agency_contract_expiry_date`, `bank_name`, `bank_bik`, `bank_iik`, `bin`, `address`, `phone`, `email`, `note`, `created_at`, `updated_at`, `fx_currency`, `fx_rate_to_kzt`, `fx_source_url`, `fx_updated_at`, `fx_status`) VALUES
(3, 'Компас', 'ТОО \"Компас\"', '1237655', '2020-02-15', 'R-4273/123', '2026-03-03', '2027-03-03', '', '', '', '123123123123', 'Алматы. Бзарбаева 162', '+77273675473', 'info@kompas.kz', '', '2026-03-02 11:44:54', '2026-03-20 03:30:01', 'USD', '0.000000', 'https://kompastour.com/kz/rus/agentam/currency_arhiv/', '2026-03-20 06:30:01', 'ok'),
(4, 'Селфи тревел', '', '', NULL, '', NULL, NULL, '', '', '', '123456789121', '', '', '', '', '2026-03-02 11:46:55', '2026-03-20 03:30:01', 'USD', '0.000000', 'https://b2b.selfietravel.kz/search_tour', '2026-03-20 06:30:01', 'ok'),
(5, 'Анекс', 'ываываыва', '', NULL, '', NULL, NULL, '', '', '', 'ываыва', 'ыва', 'ваыва', 'wrgw@ljn.ru', '', '2026-03-02 14:09:22', '2026-03-20 03:30:02', 'USD', '0.000000', 'https://online3.anextour.kz/', '2026-03-20 06:30:02', 'ok'),
(6, 'Казюнион', '', '', NULL, '', NULL, NULL, '', '', '', '', '', '', '', '', '2026-03-04 04:12:47', '2026-03-20 03:30:02', 'USD', '0.000000', 'https://online.kazunion.com/currency', '2026-03-20 06:30:02', 'ok'),
(7, 'JoinUp', '', '', NULL, '', NULL, NULL, '', '', '', '', '', '', '', '', '2026-03-04 04:13:06', '2026-03-20 03:30:02', 'USD', '0.000000', 'https://online.joinup.kz/', '2026-03-20 06:30:02', 'ok'),
(8, 'FunSun', '', '', NULL, '', NULL, NULL, '', '', '', '', '', '', '', '', '2026-03-04 04:13:27', '2026-03-20 03:30:04', 'USD', '0.000000', 'https://fstravel.asia/', '2026-03-20 06:30:04', 'ok'),
(9, 'Pegas', '', '', NULL, '', NULL, NULL, '', '', '', '', '', '', '', '', '2026-03-04 04:14:41', '2026-03-20 03:30:05', 'USD', '0.000000', 'https://kz.pegast.asia/ExchangeRates', '2026-03-20 06:30:05', 'ok'),
(10, 'ABK Tourism', '', '', NULL, '', NULL, NULL, '', '', '', '', '', '', '', '', '2026-03-04 04:15:04', '2026-03-20 03:30:06', 'USD', '0.000000', 'https://abktourism.kz/', '2026-03-20 06:30:06', 'ok'),
(11, 'Calypso Tour', '', '', NULL, '', NULL, NULL, '', '', '', '', '', '', '', '', '2026-03-04 04:15:24', '2026-03-20 03:30:06', 'USD', '0.000000', 'Ручная корректировака', '2026-03-20 06:30:06', 'fetch_failed'),
(12, 'CrystalBay', '', '', NULL, '', NULL, NULL, '', '', '', '', '', '', '', '', '2026-03-04 04:15:51', '2026-03-20 03:30:06', 'USD', '0.000000', 'https://booking-kz.crystalbay.com/search_tour', '2026-03-20 06:30:06', 'ok_partial'),
(13, 'Sanat', '', '', NULL, '123345', '2026-03-01', '2026-03-11', '', '', '', '', '', '', '', '', '2026-03-04 04:16:32', '2026-03-20 03:30:07', 'USD', '0.000000', 'https://online.sanat.kz/TourSearchClient2', '2026-03-20 06:30:07', 'ok');

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--
-- Создание: Мар 02 2026 г., 10:18
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `role` enum('manager','tourist') COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `phone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`id`, `role`, `email`, `password_hash`, `name`, `phone`, `active`, `created_at`, `updated_at`) VALUES
(1, 'manager', 'nj-rector@mail.ru', '$2y$10$6kY/BQqcGr1OKWOgH0sWMeYGllei8aPLWFVPw1iOQiTOTLXk319ji', 'Вадим', '', 1, '2026-03-02 10:18:29', NULL),
(2, 'tourist', '1turkz@mail.ru', '$2y$10$48T13cG/OqRpU8Fat1fAwuHlyYbmGA7alEh.JASxAAscmKIAdUp.O', 'Дмитриченко Вадим Александрович', '87751462072', 1, '2026-03-02 10:23:03', NULL),
(3, 'tourist', 'mame@turdok.kz', '$2y$10$3IL9TOUJQGk0.e5A9AK66OGi2b6ujs/I7LdzctPBHLNpKCu2Vt3ja', 'Петров Виктор Федорович', '56346346346', 1, '2026-03-02 10:52:52', NULL),
(4, 'tourist', 'mama@mail.ru', '$2y$10$ZylsAowM3Uf9BlXNMxou4.EPP8IkctYWI5dKqGHye2Ln/PaNuuo46', 'Федорова Елена Викторовна', '+79501562345', 1, '2026-03-02 11:06:30', '2026-03-03 07:21:56'),
(7, 'tourist', 'vadimdm830@yandex.ru', '$2y$10$Rk9obU4.R3doH3VkJ9IEz./Ys8jEEUR1CR1rh6Q/dFnEq/NA38iDm', 'Кривко Вадим Александрович', '+77751462072', 1, '2026-03-02 12:49:42', '2026-03-06 07:44:18'),
(14, 'tourist', 'lmkrivko@mail.ru', '$2y$10$h2emLlXc7msYHUTsitxlxuNCA8k7.T0ENrzJ9C0bMDbXp5xNZQVQ6', 'Кривко Лидия Михайловна', '', 1, '2026-03-06 07:45:29', NULL),
(15, 'tourist', 'valya.dmitrichenko.41@mail.ru', '$2y$10$6hRabhdapb3j.zr4Xw.TgO4KLuDsDXKbagqSEmrZ7vYHWapIaqnxu', 'Дмитриченко Валентина Ивановна', '+77752763546', 1, '2026-03-06 07:47:17', NULL),
(16, 'tourist', 'krivkosergey@yandex.ru', '$2y$10$DSCZVBUicEJ1cNm.S9Jjjum56DFUGXqhacE2FJysMjrtBg5GVNlqe', 'Кривко Сергей Борисович', '+79502196491', 1, '2026-03-06 08:00:35', NULL);

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `applications`
--
ALTER TABLE `applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_applications_status` (`status`),
  ADD KEY `idx_applications_dates` (`start_date`,`end_date`),
  ADD KEY `idx_applications_operator` (`operator_id`),
  ADD KEY `fk_app_manager` (`manager_user_id`),
  ADD KEY `fk_app_main_tourist` (`main_tourist_user_id`);

--
-- Индексы таблицы `application_documents`
--
ALTER TABLE `application_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_app_tpl` (`application_id`,`template_id`),
  ADD KEY `idx_app` (`application_id`),
  ADD KEY `idx_tpl` (`template_id`);

--
-- Индексы таблицы `application_tourists`
--
ALTER TABLE `application_tourists`
  ADD PRIMARY KEY (`application_id`,`tourist_user_id`),
  ADD KEY `fk_app_t_tourist_user` (`tourist_user_id`);

--
-- Индексы таблицы `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_companies_bin` (`bin`);

--
-- Индексы таблицы `contracts`
--
ALTER TABLE `contracts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contracts_app` (`application_id`);

--
-- Индексы таблицы `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_documents_app` (`application_id`),
  ADD KEY `idx_documents_type` (`doc_type`),
  ADD KEY `idx_documents_uploader` (`uploaded_by_role`,`uploaded_by_user_id`);

--
-- Индексы таблицы `document_templates`
--
ALTER TABLE `document_templates`
  ADD PRIMARY KEY (`id`);

--
-- Индексы таблицы `operator_fx_rates`
--
ALTER TABLE `operator_fx_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_operator_currency` (`operator_id`,`currency`),
  ADD KEY `idx_operator` (`operator_id`),
  ADD KEY `idx_captured` (`captured_at`),
  ADD KEY `idx_operator_currency_captured` (`operator_id`,`currency`,`captured_at`);

--
-- Индексы таблицы `operator_links`
--
ALTER TABLE `operator_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_app_operator` (`application_id`,`operator_id`),
  ADD KEY `idx_operator_ref` (`operator_id`,`external_ref`),
  ADD KEY `idx_operator_host` (`operator_host`);

--
-- Индексы таблицы `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payments_app` (`application_id`),
  ADD KEY `idx_payments_status` (`status`),
  ADD KEY `idx_payments_date` (`pay_date`);

--
-- Индексы таблицы `payment_deadlines`
--
ALTER TABLE `payment_deadlines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_app` (`application_id`),
  ADD KEY `idx_dir` (`direction`);

--
-- Индексы таблицы `tourists`
--
ALTER TABLE `tourists`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tourists_user_id` (`user_id`),
  ADD KEY `idx_tourists_iin` (`iin`);

--
-- Индексы таблицы `tour_operators`
--
ALTER TABLE `tour_operators`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tour_operators_bin` (`bin`),
  ADD KEY `idx_tour_operators_name` (`name`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_active` (`active`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `applications`
--
ALTER TABLE `applications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT для таблицы `application_documents`
--
ALTER TABLE `application_documents`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT для таблицы `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT для таблицы `contracts`
--
ALTER TABLE `contracts`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT для таблицы `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT для таблицы `document_templates`
--
ALTER TABLE `document_templates`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT для таблицы `operator_fx_rates`
--
ALTER TABLE `operator_fx_rates`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=84975;

--
-- AUTO_INCREMENT для таблицы `operator_links`
--
ALTER TABLE `operator_links`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT для таблицы `payment_deadlines`
--
ALTER TABLE `payment_deadlines`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT для таблицы `tourists`
--
ALTER TABLE `tourists`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT для таблицы `tour_operators`
--
ALTER TABLE `tour_operators`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `applications`
--
ALTER TABLE `applications`
  ADD CONSTRAINT `fk_app_main_tourist` FOREIGN KEY (`main_tourist_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_app_manager` FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_app_operator` FOREIGN KEY (`operator_id`) REFERENCES `tour_operators` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `application_tourists`
--
ALTER TABLE `application_tourists`
  ADD CONSTRAINT `fk_app_t_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_app_t_tourist_user` FOREIGN KEY (`tourist_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `contracts`
--
ALTER TABLE `contracts`
  ADD CONSTRAINT `fk_contracts_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `fk_documents_app` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `tourists`
--
ALTER TABLE `tourists`
  ADD CONSTRAINT `fk_tourists_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
