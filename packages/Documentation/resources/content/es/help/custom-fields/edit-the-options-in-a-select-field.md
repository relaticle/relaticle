---
title: Editar las opciones de un atributo de selección
description: Renombre, reordene, agregue o coloree las opciones de cualquier selección, incluidas las etapas del pipeline y los estados de las tareas.
order: 4
updated: "2026-08-13"
related: [help/custom-fields/add-and-manage-fields, help/getting-started/track-a-deal-through-the-pipeline, help/tasks-and-notes/use-the-task-board]
---

Usted puede editar las opciones de cualquier atributo de tipo selección, incluidas las dos selecciones que Relaticle trae ya definidas con criterio propio: el atributo **Etapa** de la oportunidad (su pipeline) y el atributo
**Estado** de la tarea (las columnas de su tablero).

1. Haga clic en el nombre de su espacio de trabajo en la parte superior de la
   barra lateral y luego en **Atributos personalizados** (solo propietarios y
   administradores del espacio de trabajo).
2. Elija la pestaña del tipo de registro: **Oportunidades** para las etapas,
   **Tareas** para los estados.
3. Abra el atributo y edite sus **Opciones**: renombre cualquier opción en su
   lugar, use **Agregar opción** para crear nuevas, arrastre para reordenar o
   quite las que no use.

![El editor de opciones del atributo Stage, con cada etapa del pipeline con su muestra de color, su cuadro de nombre, su control de arrastre y su botón de quitar](/help-assets/custom-fields/edit-the-options-in-a-select-field-1.png)

El cambio llega a todas partes a la vez: formularios, filtros de lista y
tableros, cuyas columnas parten de las opciones y de su orden. El orden y las
columnas ocultas de cada miembro se aplican encima. Si renombra "Prospección" a
"Cliente potencial nuevo", la columna del tablero cambia de nombre. Si agrega
una opción "Contrato enviado", se agrega una columna.

## Qué pasa con los registros existentes

- **Renombrar** una opción mantiene todos los registros apuntando a ella. Los
  registros muestran el nombre nuevo de inmediato.
- **Quitar** una opción la borra de los registros que la usaban. Mostrarán un
  valor vacío hasta que alguien elija uno nuevo. Verifique que la columna del
  tablero esté vacía antes de quitar una etapa.

## Colores

Si el atributo tiene activada la opción **Activar colores en las opciones** (Etapa
la tiene por defecto), cada opción recibe una muestra de color, que se usa en las
insignias de las listas y en las tarjetas del tablero. Defina los colores en el
mismo editor de Opciones.
