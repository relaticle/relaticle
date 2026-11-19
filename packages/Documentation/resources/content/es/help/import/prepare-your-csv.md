---
title: Prepare su archivo CSV
description: Requisitos del archivo, nombres de columna y reglas de formato para que una importación salga bien a la primera.
order: 1
updated: "2026-08-13"
related: [help/import/what-each-record-type-needs, help/import/fix-import-errors, help/getting-started/import-your-existing-data]
---

Un CSV se importa sin problemas cuando tiene codificación UTF-8, encabezados en
la primera fila y un formato uniforme en cada columna. Esta página explica las
reglas. El [recorrido paso a paso](/es/help/getting-started/import-your-existing-data)
explica los clics.

## Requisitos del archivo

| Requisito | Valor |
|-------------|-------|
| Formato | CSV (valores separados por comas) |
| Codificación | UTF-8 |
| Máximo de filas | 10,000 por archivo |
| Tamaño máximo | 10 MB |
| Encabezados | Obligatorios en la primera fila |

En Excel, use **Guardar como → CSV UTF-8 (delimitado por comas)**. La
codificación CSV predeterminada de Excel daña los caracteres con acento.

## Columnas obligatorias

Solo se requiere una columna por tipo de registro:

| Tipo de registro | Columna obligatoria |
|-------------|-----------------|
| Empresas | Nombre |
| Personas | Nombre |
| Oportunidades | Nombre |
| Tareas | Título |
| Notas | Título |

Todas las demás columnas son opcionales. Una celda en blanco simplemente deja
vacío ese campo.

## Columnas de atributos personalizados

Nombre las columnas de atributos personalizados con el prefijo `custom_fields_`
seguido del código del campo:

```
custom_fields_industry
custom_fields_emails
custom_fields_website
```

Encuentre el código de cada campo en **Configuración → Atributos personalizados**,
en la columna **Código**. El asistente también asigna automáticamente nombres
parecidos, así que una columna llamada "Industry" normalmente termina en el campo
Industry sin el prefijo. El prefijo hace que la coincidencia sea exacta.

## Dé formato a los valores según el tipo de campo

| Tipo de campo | Formato | Ejemplo |
|------|--------|---------|
| Texto | Texto sin formato | `Technology` |
| Número | Solo dígitos, sin símbolos | `50000` |
| Fecha | YYYY-MM-DD, DD/MM/YYYY o MM/DD/YYYY | `2024-03-15` |
| Correo electrónico | Uno o más correos válidos, separados por comas | `john@acme.com,jane@acme.com` |
| Selección | La etiqueta exacta de la opción | `Enterprise` |
| Selección múltiple | Etiquetas de opciones separadas por comas | `CRM,Analytics` |
| Booleano | true/false, yes/no o 1/0 | `true` |

## Qué hacer

- Incluya encabezados en la primera fila.
- Ponga entre comillas los valores que contengan comas: `"Last, First"`.
- Mantenga un formato uniforme dentro de cada columna, sobre todo en las fechas.
- Pruebe con una muestra pequeña (5 a 10 filas) antes de importar el archivo
  completo.

## Qué evitar

- No use la codificación predeterminada de Excel. Elija "CSV UTF-8".
- No mezcle formatos de fecha en una misma columna (`2024-03-15` y `03/15/2024`).
- No incluya símbolos de moneda en los números. Use `50000`, no `$50,000`.
- No deje filas vacías al final del archivo.

## Obtenga una plantilla lista para usar

Exporte algunos registros existentes desde cualquier lista. El archivo exportado
incluye la columna `id` y todos los atributos personalizados que usa su espacio
de trabajo, ya con el nombre correcto. Elimine las filas, conserve los
encabezados y complete sus datos.
