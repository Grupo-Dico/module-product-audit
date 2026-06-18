# Changelog

All notable changes to this module will be documented in this file.

The format is based on Keep a Changelog and this project adheres to Semantic Versioning.

## [1.0.0] - 2026-04-25

- Fix: Date and store view

## [1.0.1] - 2026-04-29

- Feature: Product change origin tracking.
- Added support to identify product updates executed from Admin, REST API, SOAP API, CLI, Cron, and Import processes.
- Added audit information to determine whether a product change was performed by a native Magento process or an external integration.
- Improved traceability of product status and attribute changes.

## [1.0.2] - 2026-06-18

- Improvement: Added detailed changed-field information to `origin_detail` for API, Admin, mass action, and CSV import updates.
- Added API payload field detection for product custom attributes and stock fields.
- Added audit support for API stock changes such as `stock.qty` and `stock.is_in_stock`.
- Enhanced import audit details to include changed attributes, store scope, attribute set, and product type when available.

## [3] - 2026-06-18

- Improvement: Added request payload summary for product audit records.
- Added `request_payload_summary` column to identify fields received from REST/SOAP API, Admin mass/action updates, and CSV import rows.
- Enhanced origin detail to keep the actor/source and changed values together for API, Admin, and import updates.
- Added Product Audit grid column `Request Changes`.
