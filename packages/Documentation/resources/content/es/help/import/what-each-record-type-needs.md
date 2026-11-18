---
title: Qué necesita cada tipo de registro
description: Columnas, relaciones y CSV de ejemplo para importar empresas, personas, oportunidades, tareas y notas.
order: 2
updated: "2026-08-13"
related: [help/import/prepare-your-csv, help/import/update-existing-records]
---

Cada tipo de registro se importa por separado, con un archivo por tipo. Esta
página enumera lo que acepta cada tipo: su columna obligatoria, las relaciones
que puede vincular durante la asignación y un CSV de ejemplo que funciona.

## Empresas

- `name` (obligatoria)
- `account_owner_email`: el correo electrónico de un miembro del espacio de
  trabajo, para asignarle la propiedad
- Atributos personalizados con el prefijo `custom_fields_`

```
name,account_owner_email,custom_fields_industry,custom_fields_domains
Acme Corporation,owner@yourcompany.com,Technology,acme.com
```

Las empresas coinciden con registros existentes por **dominio** o por **ID del
registro**. Consulte
[Actualice registros con una importación](/es/help/import/update-existing-records).

## Personas

- `name` (obligatoria)
- Atributos personalizados con el prefijo `custom_fields_`
- Relación **Empresa**: vincule cada persona con una empresa por ID del
  registro, dominio o nombre

```
name,company,custom_fields_emails,custom_fields_title
John Doe,acme.com,john@acme.com,CEO
Jane Smith,acme.com,jane@acme.com,CTO
```

Asigne la columna `company` a la relación Empresa y elija **Dominio** en
**Buscar coincidencias por**, para que `acme.com` se vincule con la empresa existente. Si elige
**Nombre** en **Buscar coincidencias por**, siempre se crea una empresa nueva.

## Oportunidades

- `name` (obligatoria)
- Atributos personalizados (monto, etapa, fecha de cierre, etcétera)
- Relación **Empresa**: por ID del registro, dominio o nombre
- Relación **Persona de contacto**: por ID del registro, correo electrónico,
  teléfono o nombre

```
name,company,contact,custom_fields_amount,custom_fields_stage
Q1 Enterprise Deal,acme.com,john@acme.com,50000,Proposal
```

Para vincular con la mayor fiabilidad, asigne `company` a Empresa → Dominio y
`contact` a Persona de contacto → Correo electrónico.

## Tareas

- `title` (obligatoria)
- Relaciones **Empresas**, **Personas** y **Oportunidades**: vincule cada tarea
  con uno o más registros
- Relación **Asignados**: asigne la tarea a miembros del espacio de trabajo por
  correo electrónico

```
title,assignee,company,custom_fields_due_date,custom_fields_priority
Follow up with client,assignee@yourcompany.com,acme.com,2024-03-15,High
```

Asigne la columna `assignee` a Asignados → Correo electrónico para asignar por
dirección de correo.

## Notas

- `title` (obligatoria)
- Relaciones **Empresas**, **Personas** y **Oportunidades**

```
title,company
Meeting Notes,acme.com
```

Las notas siempre se crean como registros nuevos. Una importación nunca
actualiza una nota existente, aunque incluya una columna `id`.
