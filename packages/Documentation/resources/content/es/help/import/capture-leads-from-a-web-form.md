---
title: Capture prospectos desde un formulario web
description: Conecte un formulario de Maxforms a Relaticle Cloud para que cada envío cree personas, empresas, oportunidades, notas y tareas vinculadas.
order: 5
updated: "2026-10-04"
related: [help/import/update-existing-records, help/ai-assistant/connect-claude-or-chatgpt, docs/guides/rest-api]
---

Relaticle no tiene su propio creador de formularios.
[Maxforms](https://maxforms.com/integrations/relaticle), un creador de
formularios, se conecta a Relaticle Cloud y convierte cada envío en registros de
su espacio de trabajo. Usted configura la conexión en Maxforms, y Relaticle le
pide aprobar el acceso una sola vez.

## Qué crea un envío

Un envío puede crear hasta cinco registros, cada uno vinculado con los demás:

| Registro | Qué suele contener |
|--------|----------------------|
| **Empresa** | La organización de quien envía el formulario |
| **Persona** | Quien envía el formulario, vinculada con la empresa |
| **Oportunidad** | Lo que abre el formulario, vinculada con ambas |
| **Nota** | Las respuestas, asociadas a los registros anteriores |
| **Tarea** | Un seguimiento, asociado de la misma forma |

Usted activa o desactiva cada registro por formulario. Un formulario que solo
necesita una persona y una nota crea únicamente esos dos.

## Conecte un formulario

1. En Maxforms, abra la pestaña **Integrate** del formulario, busque la tarjeta
   **Relaticle** y seleccione **Connect**.
2. Seleccione **Connect New Account** e inicie sesión en Relaticle si se lo piden.
3. Apruebe el acceso y **elija el espacio de trabajo** en el que el formulario
   puede escribir.
4. De vuelta en Maxforms, active los registros que quiera y asigne cada campo de
   Relaticle a una respuesta del formulario. Sus atributos personalizados
   aparecen junto a los integrados.
5. Guarde, envíe una prueba y abra los registros nuevos en Relaticle.

Maxforms pide dos permisos: leer sus registros, y crearlos y actualizarlos. No
puede eliminar nada.

## Evite duplicados

Maxforms puede relacionar un envío con un registro que usted ya tiene, en lugar
de crear un segundo registro. Las personas se relacionan por **Correos electrónicos** y
las empresas por **Dominios**, los dos campos que Relaticle mantiene únicos.

En la tarjeta de Relaticle en Maxforms, asigne la respuesta de correo del
formulario a Correos electrónicos y luego elija Correos electrónicos en **Match
existing people by**. Haga lo mismo con Dominios para las empresas.

Quien envíe el formulario otra vez actualiza su persona y su empresa existentes.
La oportunidad, la nota y la tarea se crean nuevas cada vez.

## Vea y revoque la conexión

Abra **Configuración → Tokens de acceso** desde el menú de su avatar. Maxforms
aparece en la lista **Conectores de IA** junto a los asistentes que haya
conectado, con el espacio de trabajo en el que escribe. **Revocar** corta su
acceso de inmediato, para todos los formularios que conectó. Los envíos nuevos
dejan de entregarse hasta que vuelva a conectar.

## Conviene saber

- La conexión funciona con Relaticle Cloud. Maxforms no puede conectarse a una
  instalación autoalojada. Una instalación autoalojada puede recibir los datos de
  un formulario mediante los endpoints de upsert de la
  [guía de la API REST](/es/developers/rest-api).
- Maxforms actúa como la persona que lo conectó, dentro del único espacio de
  trabajo elegido en la pantalla de consentimiento.
- No se puede elegir un espacio de trabajo en pausa en la pantalla de
  consentimiento. Las entregas a un espacio de trabajo que se pone en pausa más
  tarde fallan hasta que vuelva a tener suscripción.
