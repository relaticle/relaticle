---
title: Administre miembros y roles
description: Qué puede hacer cada rol (Propietario, Administrador, Miembro y Lector), además de invitaciones por correo y cómo quitar miembros.
order: 1
updated: "2026-11-19"
related: [help/getting-started/invite-your-team, help/workspace/rename-or-delete-your-workspace]
---

Los miembros se administran en **Configuración del espacio de trabajo**. Haga
clic en el nombre de su espacio de trabajo en la parte superior de la barra
lateral para llegar, y luego en la pestaña **Miembros**. Las invitaciones, los
roles y las bajas están allí. **Invitar personas** envía las invitaciones.
**Miembros**, debajo, enumera a todos los que tienen acceso, hayan aceptado o no.

## Un cambio reciente en los roles

El rol Editor ahora se llama Miembro. Los administradores ahora pueden
administrar los atributos personalizados, algo que antes solo podía hacer el
propietario. Revise su lista
de **Miembros** si otorgó el rol de administrador antes. Los lectores ya no
pueden exportar datos, así que dé el rol de Miembro a quien todavía necesite
exportar. Un Lector aún puede leer registros mediante un token de acceso para la
API o el MCP server.

## Los cuatro roles

| | Propietario | Administrador | Miembro | Lector |
|---|---|---|---|---|
| Ver registros | Sí | Sí | Sí | Sí |
| Crear registros | Sí | Sí | Sí | No |
| Actualizar registros | Sí | Sí | Sí | No |
| Eliminar y restaurar registros | Sí | Sí | Sí | No |
| Eliminar registros de forma permanente | Sí | Sí | No | No |
| Importar datos | Sí | Sí | Sí | No |
| Exportar datos | Sí | Sí | Sí | No |
| Invitar, quitar y cambiar el rol de los miembros | Sí | Sí | No | No |
| Promover a alguien a administrador | Sí | No | No | No |
| Administrar atributos personalizados | Sí | Sí | No | No |
| Administrar la configuración de correo del espacio de trabajo | Sí | Sí | No | No |
| Enviar correo mediante un asistente de IA | Sí | Sí | Sí | No |
| Administrar la facturación | Sí | No | No | No |
| Renombrar o eliminar el espacio de trabajo | Sí | No | No | No |
| Ver el registro de actividad | Sí | Sí | No | No |

Los administradores administran miembros, atributos personalizados y la
configuración de correo del espacio de trabajo, además de trabajar con registros.
Algunas cosas quedan solo con el **propietario del espacio de trabajo**, sea cual
sea el rol de cada quien: promover a alguien *a* administrador, renombrar o
eliminar el espacio de trabajo, y la facturación.

El propietario no es un rol que usted asigne. Quien posee el espacio de trabajo
siempre aparece con una insignia de **Propietario**, no tiene un rol que cambiar
y no puede salir ni ser quitado.

Dondequiera que elija un rol, en **Invitar miembros del espacio de trabajo** o
**Cambiar rol**, **Comparar roles** aparece junto a la opción. Abre esta tabla en la aplicación, y siempre coincide con lo que cada rol
puede hacer realmente.

## Invitaciones

Haga clic en **Invitar miembros del espacio de trabajo** para enviar hasta 10
invitaciones a la vez. Pegue las direcciones de correo separadas por una coma,
un espacio o un salto de línea, elija el rol con el que todos se unirán y envíe.

Una persona invitada se suma de inmediato a la lista de **Miembros**, por encima
de quienes ya aceptaron, marcada como **Invitación pendiente** con el rol que
recibirá y cuánto tiempo le queda a la invitación. Su menú de acciones ofrece:

- **Reenviar** el correo.
- **Revocar** la invitación.

Una invitación dura 48 horas. Después, la fila indica **Invitación vencida**, y
**Reenviar** emite una nueva.

![La lista de Miembros con un correo invitado marcado como Invitación pendiente encima de las personas que ya se unieron](/help-assets/workspace/manage-members-and-roles-1.png)

## Cambie y quite miembros

El rol de cada persona está junto a su nombre, y el menú de acciones al final de
la fila lo cambia. **Cambiar rol** la cambia entre Administrador, Miembro y
Lector. **Quitar** saca a un miembro del espacio de trabajo: sus registros
permanecen, pero pierde el acceso. Cualquier miembro, excepto el propietario,
puede **Salir** del espacio de trabajo por su cuenta. El propietario no tiene la
acción **Salir**.

La lista se puede buscar por nombre o dirección de correo, así que una lista
larga sigue siendo manejable.
