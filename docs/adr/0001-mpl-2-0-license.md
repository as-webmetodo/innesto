# License Innesto under MPL 2.0

Innesto is licensed under the Mozilla Public License 2.0 for the whole repository (PHP, JS, CSS, templates, docs). Its per-file copyleft keeps changes to Innesto's own files open, while proprietary third-party Modules, freelancers' from-scratch template overrides and the client's site itself can carry any license. We don't plan to make money from the code, so the license is chosen to make adoption easy for freelancers. Contributions use a DCO sign-off rather than a CLA. Without a CLA, changing the license later requires every contributor's consent, so this decision is hard to reverse. Full rationale: [Which open-source license does Innesto use?](https://github.com/as-webmetodo/innesto/issues/5).

## Considered Options

- **MIT**: rejected because anyone (a hosting company, an agency) could close Innesto and resell it.
- **GPL v3**: rejected because Modules loaded into Roots would generally count as derivative and be forced under the GPL, and GPL obligations on copied templates and on the Web Component JS served to every visitor would worry clients and agencies.

## Consequences

- Every source file carries `SPDX-License-Identifier: MPL-2.0`, including templates and JS that browsers receive.
- A template override copied and edited from an Innesto template is a modified MPL file: it keeps its SPDX header and stays MPL. Override docs must say so in one line.
- GPL compatibility is kept: no "Incompatible With Secondary Licenses" notice is used.
