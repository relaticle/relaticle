---
title: Controlar dónde aparece un atributo
description: Los ajustes de un atributo deciden su lugar en las listas y las páginas de registro, y cómo se filtra y se ordena.
order: 3
updated: "2026-11-10"
related: [help/custom-fields/add-and-manage-fields, help/getting-started/find-anything-with-search-and-filters]
---

Cada atributo tiene una sección de **Ajustes** que controla dónde aparece y cómo
se comporta. Abra el atributo desde la página **Atributos personalizados** para
cambiarlos. Los cambios se aplican de inmediato a todo el espacio de trabajo.

![La sección Ajustes del formulario Edit attribute con interruptores para Visible in List, Visible in View, Toggleable Hidden, Encrypted y Enable Color Options](/help-assets/custom-fields/control-where-a-field-appears-1.png)

## Visibilidad

| Ajuste | Qué hace |
|---------|--------------|
| Visible en la lista | Agrega el atributo como columna en la página de lista del tipo de registro |
| Visible en el detalle | Muestra el atributo en la página de detalle del registro |
| Oculto con opción de mostrar | Mantiene disponible la columna de la lista pero oculta, hasta que alguien la agregue desde **Configuración de la vista**, en **Agregar atributo a la vista** |

Un atributo desactivado en ambos lugares sigue existiendo en el formulario de
creación y edición. Úselo para datos que captura pero que no necesita ver a
diario.

## Filtros y orden

- Los **filtros** no necesitan ningún ajuste. Puede filtrar una lista por la
  mayoría de los atributos desde la fila de filtros que hay sobre ella. Los
  atributos de Área de texto, Editor de texto enriquecido y Selector de color no
  tienen filtro, ni tampoco un atributo cifrado. Consulte
  [Encuentre todo con búsqueda y filtros](/es/help/getting-started/find-anything-with-search-and-filters).
- La **búsqueda** no necesita ningún ajuste. Rela y el MCP server comparan los
  valores de los atributos de Texto, Área de texto, Correo electrónico, Número de
  teléfono, Enlace, Dominio y Selector de color. Omiten los atributos de
  selección, de registro, de Editor de texto enriquecido, de número, de fecha y
  de moneda. La búsqueda de la barra lateral compara nombres y títulos.
- El **orden** funciona en las columnas de texto, número, moneda y fecha. Las
  columnas de varios valores y de tipo contacto (correos, teléfonos, enlaces) no
  se ordenan.

## Estructura de los datos

| Ajuste | Qué hace |
|---------|--------------|
| Permitir varios valores | Permite que un registro tenga varios valores (con un tope que usted define, de hasta 20) |
| Único por tipo de registro | Dos registros de este tipo no pueden compartir un valor. Así evitan duplicados los atributos Dominios y Correos electrónicos |
| Cifrado | Almacena los valores cifrados en reposo. Los atributos cifrados no se pueden ordenar |
| Activar colores en las opciones | Para atributos de Selección y de Etiquetas. Da un color a cada opción, que se muestra como insignias en las listas y en el tablero |
