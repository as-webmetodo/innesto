# 🌱 Innesto

**Dynamic micro-modules for static sites and lightweight web projects. Zero CMSes, no complex databases, 100% yours.**

**Innesto** is an open-source suite of web tools and components built for developers who create small-to-medium websites (landing pages, corporate sites, SSG-generated projects) and want to add dynamic features — such as advanced contact forms, availability calendars, or real-time opening hours — without dealing with the bloat of a monolithic CMS or external SaaS subscriptions.

Just like plant grafting (*innesto* in Italian), the suite lets you take the solid, fast, and secure "trunk" of a static HTML site and graft live, functional features onto it, hosted directly on your own web space.

---

## 🎯 Why Innesto?

Web developers building sites for small businesses, professionals, or local venues always run into the same paradox:
1. **Using a traditional CMS (e.g., WordPress)** just to have a contact form and an editable opening hours page means introducing MySQL databases, constant security updates, sluggishness, and unnecessary complexity.
2. **Using Static Site Generators (Astro, Hugo, 11ty) or pure HTML/PHP** guarantees top-tier performance and zero maintenance, but as soon as a dynamic feature is needed, it forces developers to integrate costly cloud services or deny the client the ability to update simple information on their own.

**Innesto** bridges this gap: it turns any inexpensive shared hosting space with PHP support into an autonomous micro-backend, ready to use in minutes via FTP.

---

## ✨ Core Pillars

* **FTP-Ready & Zero-Config Database:** No MySQL database to set up in the hosting control panel and no mandatory terminal commands. Data is managed locally via files or SQLite.
* **Dual-Key Architecture (Developer & Client):**
  * **For the Developer:** Full control over code, visual configurators to set up modules in minutes, and absolute freedom over design and CSS.
  * **For the Non-Technical Client:** A stripped-down, essential interface (usable as a smartphone PWA) to handle daily operations — such as blocking a date on a calendar or setting holiday hours — with zero risk of breaking the site's layout.
* **Universal Integration:** Every Innesto module is designed to fit the developer's workflow, not the other way around. It can be integrated via:
  * **HTML / Static Sites (SSG):** Using dedicated Web Components.
  * **Hypermedia Libraries (htmx, Unpoly):** Using pure HTML fragments served directly from endpoints.
  * **Classic PHP:** Via direct inclusion in the page for Server-Side Rendering (SSR).
  * **Lightweight Reactive Frontends (Alpine.js, Fetch):** Via optional JSON responses.
* **Non-Intrusive Styling:** Clean semantic HTML, customizable via standard CSS variables, utility class mapping (e.g., Tailwind CSS), or full template overrides.

---

## 🧩 The Ecosystem

The suite is modular by design: install only what the project actually needs.

### 🌳 Innesto Roots *(The Core)*
This is the shared core and control center of the suite. Once uploaded to the server, it allows the developer to:
* Download and activate only the necessary packages for the project with a single click.
* Configure global shared parameters once (SMTP email delivery, GDPR-compliant anti-spam systems, timezones, access credentials).
* Generate code snippets to paste into the site's pages.
* Offer the end client a simplified, secure access point (via PIN or Magic Link) to manage their operational content.

### 📦 Core Modules *(In Design)*

* **Innesto Modulo (`in.modulo`) — Modern Form Mailer**
  The spiritual successor to classic FTP-ready form scripts (like *Tectite FormMail*), redesigned for the modern web. Features a visual configurator to define fields and validation rules, native SMTP support for reliable email delivery, invisible anti-spam protections without tracking cookies, optional notifications (e.g., Webhooks/Telegram), and a temporary log of received messages accessible to the client.

* **Innesto Agenda (`in.agenda`) — Booking & Availability Calendar**
  An availability calendar and booking request tool designed for small accommodation providers (B&Bs, vacation rentals), sports facilities, rehearsal spaces, or rental services. Supports two-way synchronization via the standard iCal (`.ics`) format to integrate with external portals, and offers the owner a clean monthly view to block dates or confirm requests right from their phone.

* **Innesto Orario (`in.hours`) — Smart Opening Hours**
  Much more than a static table: it calculates and displays real-time status (*"Open now"*, *"Closes in 30 minutes"*, *"Reopens tomorrow at 9:00 AM"*), automatically generates Schema.org structured data (`LocalBusiness`) for local SEO, and lets merchants add extraordinary closures or holiday periods in seconds.

### 🔭 Future Expansions
The *Innesto Roots* architecture is designed to welcome other vertical micro-tools over time:
* **Innesto Notice (`in.notice`):** Temporary banners and alerts with automatic expiration dates (for promos, urgent notices, or closures).
* **Innesto Menu (`in.menu`):** Agile management of price lists, services, and restaurant menus with allergen filters.
* **Innesto Gallery (`in.gallery`):** "Drop-in" photo galleries with automatic generation of optimized WebP thumbnails.

---

## 👥 Who is it for?

* **Web Designers and Freelance Developers** who build bespoke, fast, and secure sites, and want to hand a simple management tool to their clients without having to maintain a CMS.
* **Static Site Generator Users (Astro, Eleventy, Hugo, Jekyll)** who want to leverage PHP on traditional hosting to add dynamic islands without depending on external serverless architectures.
* **Fans of htmx and the "Plain Web"** looking for ready-to-use backend components capable of returning semantic HTML fragments without imposing heavy JavaScript frameworks on the frontend.

---

## 🗺️ Project Status

**Innesto** is currently in the **Concept & Architectural Design** phase. 
The goal of this stage is to define the specifications for the central hub (*Innesto Roots*), integration conventions for developers, and the behavior of the first three official packages (*Mail*, *Book*, *Hours*).

Contributions, ideas, real-world use cases, and developer feedback are very welcome in the project's [Discussions](#)!

---

## 📄 License

Distributed under an Open Source license *(to be defined: MIT / GPL / MPL)*.
