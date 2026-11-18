---
title: Actualice registros con una importación
description: Relacione filas por ID, correo, teléfono o dominio para actualizar registros en su lugar en vez de crear duplicados.
order: 3
updated: "2026-08-13"
related: [help/import/what-each-record-type-needs, help/import/fix-import-errors]
---

Cuando una columna del CSV identifica de forma única un registro (un ID, un
correo electrónico, un dominio), el asistente de importación relaciona las filas
con sus datos existentes y actualiza esos registros en lugar de duplicarlos.
Usted elige el método de coincidencia al asignar las columnas, y la vista previa
muestra exactamente qué se creará y qué se actualizará antes de que algo cambie.

## Métodos de coincidencia

| Método | Comportamiento | Disponible para |
|--------|----------|---------------|
| **ID del registro** | Actualiza ese registro exacto. Omite la fila si no encuentra el ID | Todos los tipos de registro |
| **Dominio** | Busca la empresa existente por dominio, o crea una nueva | Empresas |
| **Correo electrónico** | Busca la persona existente por correo electrónico, o crea una nueva | Personas |
| **Teléfono** | Busca la persona existente por teléfono, o crea una nueva | Personas |
| **Nombre** | Siempre crea un registro nuevo, porque los nombres no son únicos | Empresas, Personas |

El paso de vista previa etiqueta entonces cada fila:

- Coincide con un registro existente → **Actualizar**
- Sin coincidencia, o coincidencia por Nombre → **Crear nuevo**
- Un ID de registro que no existe → **Omitir**

Si no asigna ninguna columna que permita coincidencias, el asistente le avisa
que todas las filas se crearán como nuevas. Puede volver y asignar una, o
continuar de todos modos.

## Cómo se comportan las actualizaciones

- **Las celdas en blanco se ignoran.** Una celda vacía del CSV conserva el valor
  existente. Incluya solo las columnas que quiera cambiar.
- **Los campos de varios valores se combinan.** Los correos electrónicos, los
  teléfonos, las etiquetas y los valores de selección múltiple se suman a lo que
  ya tiene el registro, no lo reemplazan.
- **Los duplicados dentro del archivo se fusionan.** Si dos filas corresponden al
  mismo registro (por ejemplo, el mismo correo dos veces), la segunda fila
  actualiza el resultado de la primera en lugar de crear un duplicado.

## Actualice por ID del registro

Para actualizaciones precisas, parta de una exportación:

1. **Exporte** los registros que quiere cambiar. El archivo incluye una columna
   `id`.
2. **Edite** el CSV y deje intacta la columna `id`.
3. **Vuelva a importar**. Las filas con un ID válido actualizan esos registros
   exactos.

```
id,name,custom_fields_industry
01KCCFMZ52QWZSQZWVG0AP704V,Acme Corporation,Software
01KCCFN1A8XVQR4ZFWB3KC5M7P,TechStart Inc,Hardware
```

## Cree y actualice en un mismo archivo

Las filas con ID actualizan. Las filas con el ID en blanco crean:

```
id,name
01KCCFMZ52QWZSQZWVG0AP704V,Update This Company
,New Company (blank ID = create new)
```
