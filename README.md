# LeanCommerce Product Audit

Módulo personalizado para Magento 2 que permite auditar cambios realizados sobre productos, tanto desde edición manual en el administrador como mediante importaciones CSV y acciones masivas desde el grid de productos.

## Funcionalidades principales

### Auditoría de cambios de producto
Registra automáticamente cambios en atributos importantes cuando un producto es guardado desde el administrador.

### Auditoría de importaciones CSV
Detecta cambios realizados mediante importación de productos desde System → Import comparando el valor anterior vs el nuevo valor importado.

### Auditoría de acciones masivas
Permite registrar cambios cuando se habilitan o deshabilitan productos desde el grid de catálogo mediante acciones masivas.

### Histórico de cambios en Admin
Ruta:
Content → Product Audit → Product Change Log


## Compatibilidad

Magento 2.4.5
PHP 7.4

## Instalación

app/code/LeanCommerce/ProductAudit

bin/magento setup:upgrade
bin/magento cache:flush
bin/magento setup:di:compile

## Autor

LeanCommerce
