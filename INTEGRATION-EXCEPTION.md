# Relaticle Integration Exception

Version 1.0, effective 2026-11-01.

Relaticle is licensed under the GNU Affero General Public License, version 3 ("AGPLv3"), in [LICENSE](LICENSE), except the code in `packages/Cloud`, which its own license governs. The AGPLv3 part carries the following additional permission.

## Additional permission under AGPLv3 section 7

As an additional permission under section 7 of the AGPLv3, Relaticle, Inc. grants you the following rights, for the parts of Relaticle it owns or is licensed to sublicense on these terms.

1. **Definitions.**

   "Relaticle" means the Program, as the AGPLv3 defines it, that Relaticle, Inc. distributes with this permission, and any covered work based on it that still carries this permission. It excludes `packages/Cloud`.

   "Integration Interfaces" means the network interfaces Relaticle provides for other software to exchange data with it, on whatever host or path they are served: the REST API, the Model Context Protocol (MCP) server with its file upload endpoint, the OAuth and discovery endpoints that authorize clients of either, the OpenAPI documents that describe the REST API, and any other interface Relaticle documents for that purpose.

   "Interface Material" means what the Integration Interfaces return to describe themselves: OpenAPI documents, MCP tool, resource, and prompt names, descriptions, and schemas, server instructions, and error messages. It also means the code samples in Relaticle's API and MCP documentation.

   "Integration" means a work that exchanges data with Relaticle only through the Integration Interfaces and contains no part of Relaticle other than Interface Material. Code loaded into or run inside a Relaticle process is not an Integration.

2. **Permission.** Relaticle, Inc. does not regard an Integration as a work based on Relaticle. To the extent an Integration would otherwise be a covered work, you may make, run, modify, and convey it, including the Interface Material in it, and make it available for interaction over a network, under terms of your choice, even if that would otherwise violate the AGPLv3.

3. **Limits.** This permission does not apply to Relaticle itself: if you modify Relaticle, the AGPLv3, including section 13, applies to your modified version in full. If you convey a modified version, you may keep this permission on it or remove it, as section 7 allows. This permission grants no rights to the Relaticle trademarks (see [.github/TRADEMARK.md](.github/TRADEMARK.md)).

4. **No adverse inference.** This permission does not imply that a work which interacts with Relaticle by other means, such as reading its database or exchanging files, is a work based on Relaticle.

This permission is irrevocable for the term of copyright. It is a grant of additional permission, not a restriction, and nothing in it limits any rights otherwise granted by the AGPLv3.
