# PropertyTrail — Product Specification v1.1

Property Condition, Inspection, Inventory & Maintenance Platform
Organisation: Zekini · Initial deployment: South Africa · Initial customer: Zekini / private property portfolio (8 properties)
Product type: Multi-tenant SaaS · Primary interface: React SPA / Progressive Web App · Backend: Laravel API
Status: Initial development specification (provisional name and domains)

v1.1 adds section 30 (ACC-654, both sides). Nothing from v1.0 has been removed.

---

## 0. Decisions for the owner (open)

| # | Question | Why it matters |
|---|---|---|
| D1 | Which repo builds ACC-654 (move-in/move-out photos per property and lease)? It sits on the ACC board (Accounting). | Accounting CLAUDE.md owns accounts/ledger/invoices; PropertyTrail owns inspections and evidence. See section 30. |
| D2 | Is the product name PropertyTrail final, or do trademark and domain checks come first? | Repo, domain and DNS names depend on it. |
| D3 | Is the first build Phase 0 (discovery) only, as the spec recommends? | Phase 0 is compulsory before any code. |

---

## 1. Product vision

### 1.1 Concept
PropertyTrail is a mobile-first property condition, inspection, inventory, maintenance and evidence management platform. It keeps a chronological, verifiable record of the physical condition, contents, occupancy and maintenance history of rental properties.

It supports two rental models: long-term residential rentals, and short-term/holiday rentals. It covers the full maintenance and repair lifecycle, with participation by tenants, landlords, property managers, cleaners, contractors and insurance assessors.

Central principle: **One property. One history. Every inspection, item, incident and repair documented.**

The participant should not need to install an application or understand property management software. They receive a link, open it in the browser and complete the assigned task.

### 1.2 Primary objectives
- Protect property owners' assets.
- Reduce disputes about property damage.
- Establish reliable incoming and outgoing inspection records.
- Monitor property deterioration over time.
- Record and reconcile contents and inventory.
- Document short-term rental turnover.
- Simplify maintenance reporting and contractor management.
- Maintain evidence for insurance claims.
- Reduce unnecessary physical inspections.
- Establish a complete historical property record.

### 1.3 Product philosophy
- Mobile-first, not desktop-first.
- Photography and evidence are central to the experience.
- Minimal friction for external participants.
- One shared property record.
- Every important action is auditable.
- AI assists with analysis but does not make final liability decisions.
- Reuse existing Zekini products and infrastructure.
- Avoid unnecessary dependencies and paid third-party services.

---

## 2. Branding and identity

### 2.1 Product name
Working name: **PropertyTrail**.

Tagline: *Know your property's history. Protect what matters.*

Alternatives:
- Every property has a story. Keep the evidence.
- Document. Protect. Maintain.
- Your property's complete history, in one place.

The name is provisional. Trademark, company-name and domain availability checks are required before public launch.

### 2.2 Brand positioning
PropertyTrail is not a property listing portal or a rental booking system. It is the **operational evidence layer** that sits alongside existing property management and booking systems.

Target audiences: individual landlords; property investors; residential property managers; holiday accommodation owners; short-term rental operators; cleaning and maintenance companies; contractors; insurance-related service providers.

### 2.3 Visual identity
Aesthetic: professional, trustworthy, modern and practical.

Avoid: real estate agent aesthetics; excessive gradients; cartoon illustrations; overly decorative interfaces; generic property listing imagery.

| Colour | Hex | Usage |
|---|---|---|
| Midnight Navy | #172B4D | Primary brand |
| Ocean Teal | #138A8A | Primary actions |
| Light Background | #F6F8FA | Application background |
| White | #FFFFFF | Cards and surfaces |
| Slate | #64748B | Secondary text |
| Success | #16865A | Completed / verified |
| Warning | #D99A27 | Attention required |
| Danger | #C84747 | Damage / critical |
| Border | #E2E8F0 | Dividers |

Typography: Inter, or an existing Zekini-approved font. Clear hierarchy. Readable on small screens. Avoid small text.

UI characteristics: rounded cards with restrained radius; subtle borders; clear visual status indicators; large touch targets; strong photographic presentation; minimal navigation on mobile.

Create a central design-token system. Do not hardcode colours, spacing or typography in components.

### 2.4 Logo
A simple, recognisable mark incorporating property, history, documentation and protection. It must work as: full horizontal lockup; square app icon; monochrome; small favicon.

---

## 3. Domain and deployment

### 3.1 Proposed domains (subject to availability)
| Purpose | Address |
|---|---|
| Primary | propertytrail.co.za |
| Application | app.propertytrail.co.za |
| API | api.propertytrail.co.za |
| Participant access | app.propertytrail.co.za/i/{secure-token} |
| Public report access | app.propertytrail.co.za/r/{secure-token} |
| Marketing site | www.propertytrail.co.za |

### 3.2 Infrastructure
- Use existing Zekini hosting and deployment conventions: Ubuntu, Nginx, Laravel, PostgreSQL, GitHub deployment pipelines, Xneelo where appropriate.
- Do not introduce new hosting without justification.
- Development, staging and production are separated. Environment-specific configuration.

### 3.3 PWA
- Installable optionally through the browser. No App Store or Play Store distribution. No native app requirement.
- Works in Safari on iPhone and Chrome on Android. Responsive desktop administration interface.
- Browser-based camera access. Offline-capable inspection workflow where practical.
- Fully usable without installation.

---

## 4. Technology stack
Reuse existing Zekini technology and conventions wherever possible.

**Backend:** Laravel; PHP version aligned with existing Zekini apps; REST API; PostgreSQL; Laravel Sanctum or existing Zekini auth; Laravel Queues, Scheduler, Events; Notifications where appropriate; existing shared Laravel packages and conventions.

**Frontend:** React; TypeScript; Vite; Tailwind CSS; existing Zekini component libraries; React Router; TanStack Query or existing API-state conventions; IndexedDB for offline work; PWA service worker. No second frontend framework.

**Storage:** photographs and documents never stored in PostgreSQL. S3-compatible object storage; private buckets; signed upload/download URLs; background image processing; thumbnail generation; original preservation. Storage adapter configurable so the existing Zekini storage can be reused.

**Architecture:** the React SPA talks to Laravel through versioned APIs (`/api/v1/`). Separate business logic from controllers. Use Services, Actions where appropriate, Policies, Form Requests, Jobs, Events, Notifications, and Resource transformers. Avoid excessive abstraction and unnecessary microservices. **A modular Laravel monolith is preferred.**

---

## 5. Existing Zekini / Fleet product reuse (MANDATORY)

Before implementing any functionality, inspect the existing Zekini repositories and Fleet ecosystem. Do not rebuild functionality that already exists. Repositories live under `~/projects/zekini/`. The Fleet orchestrator is `~/projects/zekini/kadro_agent.py`. Use the Kadro MCP integration to discover relevant projects, cards and status.

### 5.1 Required discovery
| Capability | Required reuse |
|---|---|
| Accounting | Existing Zekini accounting/invoicing product |
| WhatsApp | Existing WhatsApp integration/product |
| Email | Zekini mailer |
| SMS | Existing SMS infrastructure |
| Pulse | Existing Pulse product/services |
| Authentication | Existing Zekini identity/authentication conventions |
| AI | Existing provider management and AI infrastructure |
| Content moderation | ContentGuard where applicable |
| Notifications | Existing shared notification services |
| Billing | Existing accounting and subscription infrastructure |
| Reporting | Existing Zekini reporting engine where suitable |
| File handling | Existing storage and document conventions |
| Audit | Existing audit/logging infrastructure |

Precise repository names, APIs, auth requirements and status must be established by inspection. **Do not guess or invent API endpoints.**

### 5.2 Integration requirements
For each integration: identify the repository; establish production-ready / partial / WIP; identify API contracts; identify auth requirements; determine whether a reusable SDK/client exists; document required changes; implement an adapter in PropertyTrail.

PropertyTrail must not couple to another product's internals. Adapters: `AccountingAdapter`, `WhatsAppAdapter`, `MailAdapter`, `PulseAdapter`, `StorageAdapter`, `AIProviderAdapter`. Where a product lacks a suitable API, identify the minimum enhancement. Do not duplicate its business logic.

### 5.3 WhatsApp
Use existing Zekini WhatsApp. Uses: inspection reminders; guest check-in instructions; guest checkout reminders; maintenance notifications; contractor job assignments; completion notifications; inspection report links. No separate WhatsApp integration. Respect template and customer-initiated messaging rules.

### 5.4 Email
Use the Zekini mailer. Uses: invitations; inspection reports; maintenance notifications; contractor communications; insurance evidence packs; account administration.

### 5.5 Accounting
Use the existing accounting product for: subscription invoices; customer billing; payment records; tax-related documents; optional maintenance invoice records. No independent accounting module. PropertyTrail may keep operational records of contractor quotes and invoices, but accounting transactions stay in the accounting product.

### 5.6 Pulse
Inspect Pulse and document its actual capabilities. Reuse for: operational notifications; activity monitoring; scheduled reminders; health monitoring; analytics/reporting where supported. Document the real integration, not assumed capabilities.

### 5.7 AI providers
Use existing Zekini AI provider configuration and rotation. Do not hardcode vendors. AI must be configurable and capable of being disabled.

---

## 6. Multi-tenancy
Multiple independent organisations. Initial tenant: Zekini / private property portfolio. Each tenant owns its users, properties, inspections, inventory, occupancy records, maintenance records, contractors, reports and subscription. **Strict tenant isolation is mandatory.**

### 6.1 Hierarchy
Organisation → Users · Properties → Areas/Rooms · Inventory · Occupancies · Inspections · Maintenance · Evidence.

A user may belong to more than one organisation. A contractor may serve multiple organisations but sees only explicitly assigned jobs.

### 6.2 Strategy
Shared PostgreSQL database with `organisation_id` on every tenant-owned record. Global tenant scoping; explicit authorisation policies; tenant-aware background jobs; tenant-aware file access; tenant-aware reporting; automated cross-tenant isolation tests. **Never rely on frontend filtering for security.**

---

## 7. Authentication and access levels
Permanent users and temporary participants are distinct. **External participants must not need an account to complete an assigned task.**

### 7.1 Roles
| Role | Access |
|---|---|
| Platform Super Admin | Global platform administration |
| Organisation Owner | Full organisation control |
| Organisation Admin | Organisation administration |
| Property Manager | Assigned property management |
| Property Owner | Own/assigned property visibility |
| Tenant | Assigned tenancy and inspections |
| Guest | Assigned short-term occupancy tasks |
| Cleaner | Assigned turnover inspections |
| Contractor | Assigned maintenance jobs |
| Insurance Assessor | Explicitly shared claim/evidence access |
| Read-only Auditor | Read-only authorised records |

### 7.2 Permanent authentication
Existing Zekini auth. Email/password where appropriate; password reset; email verification; optional MFA; session management; role-based access; organisation membership.

### 7.3 Temporary participant access
Tenants, guests, cleaners and contractors receive secure task links, e.g. `app.propertytrail.co.za/i/{token}`.
- Cryptographically secure tokens; store only token hashes.
- Expiry dates; scope restrictions; revocation.
- Optional OTP verification; rate limiting; access logging.
- A guest sees only their check-in/check-out workflow. A contractor sees only assigned work orders.

### 7.4 Permissions (granular)
Can view property · edit property · create inspection · complete assigned inspection · view historical inspection · upload evidence · view inventory · modify inventory · create maintenance request · assign contractor · approve quotation · close work order · export reports.

Tenants and guests never see private owner notes or unrelated historical information.

---

## 8. Core data model
Organisation · User · Membership · Role · Permission · Property · PropertyAddress · PropertyArea · PropertyAsset · InventoryCategory · InventoryItem · InventoryTemplate · InventorySnapshot · Occupancy · InspectionTemplate · Inspection · InspectionSection · InspectionItem · InspectionResponse · Evidence · EvidenceRelationship · MaintenanceRequest · WorkOrder · Contractor · ContractorAssignment · Quotation · InvoiceReference · InsuranceClaim · Notification · AuditEvent · Subscription · Plan · UsageRecord.

Every tenant-owned record is organisation-scoped. Every important record has: UUID; created and updated timestamps; created-by; organisation; appropriate status. Soft delete only where operationally appropriate. **Evidence records are never silently deleted.**

Lease/occupancy linkage for ACC-654 is in section 30.

---

## 9. Property management

### 9.1 Registration
Property name; address; property type; long-term, short-term or mixed use; bedrooms; bathrooms; description; owner; property manager; contact information; access instructions; emergency contacts; optional GPS coordinates; property photographs.

### 9.2 Areas and rooms
Configurable per property. Examples: entrance, lounge, kitchen, dining room, bedrooms, bathroom, garage, garden, swimming pool, balcony, exterior. Each area can have an inspection checklist, photo requirements, inventory, assets and maintenance history. Custom areas allowed.

---

## 10. Inspection module (primary)

### 10.1 Incoming inspection
Before or at the start of occupancy. Captures overall condition; room condition; walls; floors; ceilings; doors; windows; fixtures; appliances; existing damage; existing defects; photographs; comments. Records tenant, landlord/agent, inspection date, acknowledgement, signatures and outstanding issues.

### 10.2 Interim inspection
Recurring during tenancy. Frequency: monthly; every 3 months; every 6 months; every 12 months; custom. Tenant receives an invitation. The mobile interface guides through required photos and questions. Compares against the incoming inspection, the previous interim inspection and relevant maintenance records. Identifies new damage, deterioration, leaks, damp, mould, cracks and maintenance requirements. Tenant can report issues independently.

### 10.3 Outgoing inspection
At tenancy end. Repeats the incoming structure. Compares initial condition, previous interims, final condition, reported maintenance and repairs performed. Generates a discrepancy report that clearly separates existing defects, normal wear and tear, potential new damage and outstanding maintenance. **Does not automatically determine financial liability.**

### 10.4 Templates
Configurable: mandatory and optional questions; photo requirements; condition ratings; comments; custom categories; organisation-specific templates.

---

## 11. Short-term rental module

### 11.1 Occupancy
Initially manual booking entry. Capture: guest name; contact details; arrival and departure date/time; property; number of guests; booking reference; optional booking platform. Design an adapter for future Airbnb/Booking.com or channel-manager integration. **Not an MVP dependency.**

### 11.2 Check-in (before arrival)
Verify property condition; photograph relevant rooms; verify inventory; record quantities; record existing damage; record cleanliness; complete inspection. Completed by a cleaner, property manager or owner depending on operating model.

### 11.3 Check-out (after departure)
Repeat condition and inventory verification; photograph discrepancies; record missing, damaged and excessively-cleaned items; record maintenance issues; generate a booking-specific discrepancy report.

### 11.4 Inventory (major differentiator)
Examples by area:
- **Kitchen:** dinner plates, side plates, bowls, cups, mugs, drinking glasses, wine glasses, forks, knives, teaspoons, serving utensils, pots, pans.
- **Bedrooms:** pillows, pillowcases, duvets, blankets, sheets.
- **Bathrooms:** towels, bath mats, other supplied items.
- **Appliances:** kettle, toaster, microwave, refrigerator, television, washing machine.
- **Outdoor:** chairs, tables, braai equipment, pool equipment.

Per item: description; category; expected quantity; actual quantity; condition; photograph; replacement value (optional); serial number (optional); notes.

### 11.5 Reconciliation
Compare check-in and check-out snapshots. Example:

| Item | Check-in | Check-out | Difference |
|---|---|---|---|
| Dinner plates | 12 | 12 | 0 |
| Drinking glasses | 12 | 11 | -1 |
| Forks | 12 | 12 | 0 |
| Teaspoons | 12 | 10 | -2 |
| Bath towels | 8 | 8 | 0 |

Highlight discrepancies. **Never automatically accuse a guest of causing damage.** Keep evidence and allow manual verification.

### 11.6 Future AI inventory recognition
Recognise items in photos; estimate counts; identify likely missing items; compare photos; flag uncertainty. **AI counts must be confirmed by a human before being treated as verified inventory.**

---

## 12. Maintenance and repairs (core)
Initiated by tenant, guest, landlord, property manager, cleaner, contractor or insurance assessor.

### 12.1 Request
Property; room/area; problem category; description; urgency; photographs; videos where supported; date reported; reporter; access requirements.

Categories: plumbing, electrical, structural, roofing, damp and mould, appliances, windows and doors, security, garden, pool, general maintenance.

### 12.2 Workflow
Reported → Under assessment → Quotation requested → Quotation received → Approval pending → Approved → Assigned → In progress → Completed → Verification → Closed. Configurable variations allowed.

### 12.3 Contractor participation
Contractors use secure task links. They can: view the assigned issue; view relevant photographs; upload before photos; add assessment notes; submit quotations; record work undertaken; upload after photos; attach invoices; request approval; mark work complete. They **cannot** see unrelated property information.

### 12.4 Before-and-after evidence
Every repair supports: original problem photos; contractor assessment; work description; before photos; during photos (optional); after photos; completion date; contractor identity; owner verification; supporting documents. **Preserve all stages. Never overwrite original evidence.**

### 12.5 Approvals
Approve/reject quotations; request more information; assign contractors; approve completion; close jobs. Record who approved what and when.

---

## 13. Insurance and incidents

Incidents that may lead to claims: storm damage, flooding, burst pipes, fire, theft, structural damage, accidental damage.

**Incident record:** incident date; discovery date; description; property; affected areas; reporter; photos; videos; supporting documents.

**Claim record:** insurer; policy reference; claim number; assessor; date submitted; claim status; assessment documents; quotations; repair records; invoices; outcome.

**Evidence pack** (downloadable, controlled sharing with assessors): incident report; chronological timeline; relevant historical inspections; original photos; damage photos; contractor assessments; quotations; repair evidence; invoices; supporting documents.

---

## 14. Evidence management (foundation)
Every photo/document belongs to a specific context: property, room, inventory item, inspection, occupancy, maintenance request, work order, insurance incident. (ACC-654 adds lease as a context: section 30.)

### 14.1 Requirements
Original file; SHA-256 hash; server receipt timestamp; uploader; organisation; associated record; optional device metadata; optional GPS with consent; processing history. Preserve originals. Generate thumbnails and display versions separately. **Never silently replace an upload.** Annotated or edited versions are stored alongside the original.

### 14.2 Audit trail
Immutable-style records for: inspection created; inspection submitted; evidence uploaded; evidence associated; inspection acknowledged; maintenance reported; contractor assigned; quotation approved; repair completed; record amended; report generated; access granted/revoked. Each record identifies actor and timestamp. **Do not claim photos are independently verified or legally conclusive.**

---

## 15. Mobile camera experience (most important UX)

### 15.1 Camera requirements
- `MediaDevices.getUserMedia()`; rear camera; live preview; capture button; retake; accept; multiple photos; flash/torch where available.
- HTML file-input fallback with `capture="environment"`.
- Test on iPhone Safari and Android Chrome. Do not assume identical behaviour across browsers.

### 15.2 Workflow example
```
PROPERTY: BEACH HOUSE 01
KITCHEN
  Photograph the complete room.
  Photograph countertops.
  Photograph cupboards.
  Photograph appliances.
  Verify cutlery.
  Verify crockery.
Progress: 4/6        [Take photograph]   [Next section]
```

### 15.3 Offline
Save inspection progress locally; store pending photos in IndexedDB; upload queue; retry failed uploads; resume interrupted uploads; prevent duplicate submissions; clear sync status. **Do not mark an inspection submitted until the server confirms receipt of required evidence.**

---

## 16. AI capabilities (incremental, optional)

**Initial:** compare historical photos; identify possible visible deterioration; identify likely new damage; categorise maintenance reports; generate inspection summaries; generate maintenance summaries.

**Future:** recognise and count inventory (crockery etc.); compare room photos; identify possible mould/damp; identify potential structural defects; generate insurance evidence summaries; detect recurring maintenance problems.

All AI output includes confidence or uncertainty where applicable. **AI must not independently:** determine tenant liability; deduct deposits; reject insurance claims; declare a property structurally safe; make legal conclusions. Human approval is required.

---

## 17. Reporting
Required reports: incoming inspection; interim inspection; outgoing inspection; check-in; check-out; inventory reconciliation; maintenance work order; repair before/after; insurance evidence pack; property history.

Reports must be professionally formatted; include property identification, inspection dates, participants, photos, notes and discrepancies, acknowledgements/signatures where applicable, generation timestamp and report reference. PDF export. Use existing Zekini reporting infrastructure where suitable.

---

## 18. Notifications
Use existing Zekini notification infrastructure. Channels: email, WhatsApp, SMS, Web Push.

| Event | Recipient |
|---|---|
| Inspection scheduled | Tenant |
| Inspection overdue | Tenant / manager |
| Inspection completed | Property manager |
| Maintenance reported | Property manager |
| Contractor assigned | Contractor |
| Quotation submitted | Property manager |
| Quotation approved | Contractor |
| Work completed | Property manager |
| Verification required | Assigned participant |
| Guest checkout approaching | Cleaner |
| Turnover inspection overdue | Manager |

Configurable per organisation. Avoid notification spam.

---

## 19. Subscriptions and pricing (provisional)
Initial deployment is internal. Pricing is provisional and must be validated against operating costs and competitors. Plans are configurable and **never hardcoded**.

| Plan | Price | Scope |
|---|---|---|
| Starter | R149/month | Up to 3 properties; basic inspections; photo evidence; basic reports; maintenance requests; limited users |
| Landlord | R349/month | Up to 10 properties; long-term inspections; interim scheduling; inventory; maintenance; historical comparisons; PDF reports |
| Professional | R699/month | Up to 30 properties; long-term and short-term; occupancy; inventory reconciliation; contractor access; advanced reporting; more users; AI-assisted analysis |
| Portfolio | From R1,499/month | Up to 100 properties; multi-user admin; advanced permissions; contractor management; insurance evidence packs; AI; higher storage and usage |

Additional property blocks and usage-based services may be added.

**Billing:** use the existing Zekini accounting and billing product. Monthly and annual subscriptions; trials; upgrades/downgrades; property limits; user limits; storage limits; usage tracking; suspension and reactivation. No separate invoicing engine.

---

## 20. Security and POPIA
Design for POPIA: data minimisation; purpose limitation; consent where required; retention policies (configurable); secure authentication; role-based access; tenant isolation; encrypted transport; private file storage; signed file URLs; audit history; controlled external sharing; secure token handling; backup and recovery procedures.

**Never expose:** private owner notes; tenant contact details; other tenants' records; unrelated property history; internal maintenance discussions. External access is scoped and revocable.

---

## 21. User interface

### 21.1 Desktop administration
Primary navigation: Dashboard · Properties · Inspections · Occupancies · Inventory · Maintenance · Contractors · Incidents & Claims · Reports · Settings.

Property detail tabs: Overview · Timeline · Inspections · Inventory · Occupancies · Maintenance · Evidence · Documents · **Leases** (added by section 30).

### 21.2 Mobile participant interface
Minimal navigation. The user sees only their assigned task.

```
YOUR INSPECTION
Beach House 01
Complete by: 25 November
[Start inspection]
```

No unnecessary dashboards or admin functions.

---

## 22. Dashboard
Total properties; upcoming inspections; overdue inspections; current occupancies; upcoming check-ins; upcoming check-outs; open maintenance requests; jobs awaiting approval; jobs awaiting verification; inventory discrepancies; recent incidents; recent activity. Filter by property and organisation.

---

## 23. Development phases
Build incrementally. Every phase produces working, tested functionality.

**Phase 0 — Discovery and architecture (compulsory, before code):** inspect all Zekini repos; identify shared services; inspect Fleet products; identify accounting, WhatsApp, mailer, Pulse, auth, UI libraries, deployment and storage conventions. Deliverables: repository discovery report; integration matrix; architecture diagram; data model; API conventions; development plan; identified dependencies.

**Phase 1 — Platform foundation:** Laravel app; React SPA; PWA foundation; PostgreSQL schema; multi-tenancy; authentication; roles and permissions; organisation management; property registration; rooms and areas; evidence storage; audit logging; basic dashboard. *Acceptance:* create organisation; add users; create properties; add rooms; upload photos; verify tenant isolation; access audit history.

**Phase 2 — Inspection engine:** templates; incoming, interim and outgoing inspections; mobile inspection UI; guided camera; photo uploads; comments; signatures/acknowledgements; scheduling; reminders; PDF reports; historical comparisons. *Acceptance:* complete an entire incoming and outgoing inspection on an iPhone and an Android phone.

**Phase 3 — Short-term rental and inventory:** occupancy; guest records; check-in; check-out; inventory templates; expected and actual quantities; snapshots; reconciliation; discrepancy reports; cleaner access; turnover notifications. *Acceptance:* run a complete holiday booking lifecycle including inventory reconciliation.

**Phase 4 — Maintenance and contractors:** requests; photo evidence; work orders; contractor management; secure contractor access; quotations; approval workflow; before/after photos; completion verification; invoice attachments; maintenance reporting. *Acceptance:* a tenant reports a problem, a contractor is assigned, work completes, and the full evidence history is available.

**Phase 5 — Insurance and advanced evidence:** incidents; claims; assessor access; historical evidence selection; evidence packs; chronological reports; advanced audit history. *Acceptance:* generate a complete insurance evidence pack from an incident and its property history.

**Phase 6 — AI and automation:** photo comparisons; damage identification; maintenance classification; inspection summaries; inventory recognition and counting; discrepancy suggestions; recurring maintenance analysis. *Acceptance:* AI suggestions are reviewable and never silently modify verified records.

**Phase 7 — Commercialisation (do not prioritise over internal needs):** subscriptions; plan configuration; usage limits; accounting integration; onboarding; organisation admin; marketing site; product analytics; support workflows; SaaS admin.

**Lease photo phase (ACC-654):** see section 30.

---

## 24. Development slices and Kadro
Dedicated Kadro board for PropertyTrail. Small, independently deliverable cards per phase.

Epics: PT-001 Platform foundation · PT-002 Multi-tenancy · PT-003 Authentication and access · PT-004 Property management · PT-005 Evidence engine · PT-006 Inspection engine · PT-007 Mobile photography · PT-008 Long-term rentals · PT-009 Short-term rentals · PT-010 Inventory · PT-011 Maintenance · PT-012 Contractors · PT-013 Insurance · PT-014 Reporting · PT-015 Notifications · PT-016 AI · PT-017 Billing · PT-018 Commercialisation · **PT-019 Lease documents (ACC-654)**.

Each card: clear objective; functional requirements; technical requirements; dependencies; acceptance criteria; tests required. Coordinate architectural changes so workers do not overlap.

---

## 25. Testing and delivery
Required: Laravel unit and feature tests; Pest browser tests where applicable; Playwright end-to-end; API permission tests; multi-tenant isolation tests; file access tests; mobile browser testing; camera compatibility tests; offline upload tests; integration tests. Every phase has acceptance tests.

Delivery: implement → run relevant tests → fix → repeat until green → browser tests → independent code review → adversarial review where appropriate → repeat tests → commit and push → existing GitHub/Xneelo deploy → verify deployed `/version` → update Kadro. **Never mark a feature complete on successful compilation alone.**

---

## 26. MVP scope
Built for the actual eight-property portfolio. MVP includes: organisation and user management; property registration; rooms and areas; guided mobile photography; incoming, interim and outgoing inspections; short-term occupancy; check-in/check-out; inventory templates; inventory reconciliation; maintenance reporting; contractor access; before/after repair photos; basic PDF reports; property timeline; email/WhatsApp notifications via existing Zekini products; **lease photo uploads per property and lease (ACC-654, section 30)**.

Deferred: advanced AI; external booking integrations; complex commercial billing.

The product must be useful without AI. Get the inventory workflow working early (priority for the Jeffreys Bay properties).

---

## 27. Non-goals
Not: a property listing portal; an estate agency CRM; a booking marketplace; a full property accounting system; a rental payment collection platform; a general project management tool; a replacement for Airbnb or Booking.com. Integrate with those ecosystems where appropriate.

---

## 28. Success criteria

**Long-term rental:** tenant receives a link, completes incoming inspection, submits photos, acknowledges condition. Three months later, completes an interim inspection. On exit, the system compares against the original and subsequent history.

**Short-term rental:** cleaner completes check-in verification and records expected inventory. After departure, cleaner completes check-out. System identifies missing or damaged items and produces a discrepancy report.

**Maintenance:** tenant reports a leak; contractor receives a link, assesses, submits a quotation, completes repair, uploads before/after photos. Owner has the full evidence history.

**Insurance:** after a storm, owner generates a chronological evidence package with prior condition records, incident photos, assessments, quotations and repair documentation.

**Operational:** owner manages all eight properties from one dashboard without visiting each for routine documentation.

---

## 29. Instructions to the AI development agent
First responsibility is understanding the Zekini/Fleet ecosystem, not writing code. Inspect repos, identify reuse. Do not rebuild accounting, email, WhatsApp, SMS, Pulse, authentication, existing AI infrastructure, or shared reporting and notification services. Use existing products through documented APIs and adapters. Build a modular Laravel backend and React TypeScript SPA.

Priorities: correct data architecture; evidence integrity; mobile usability; multi-tenant security; reuse; small independently testable slices. Build for the operational reality first. No speculative commercial features. No unnecessary paid services. AI is never a prerequisite for core functionality.

**Owner's recommendations (preserved):**
| Decision | Recommendation |
|---|---|
| Product architecture | Modular Laravel monolith |
| Frontend | Independent React SPA/PWA |
| External users | Token-based access; no mandatory accounts |
| Evidence | Permanent originals, hashes and audit history |

Do first: (1) Phase 0 is compulsory; no AI worker builds another mailer, WhatsApp integration or accounting system. (2) Get the inventory workflow working early. (3) Do not start with AI.

**Web vs native:** the owner wants a browser-based (PWA/SPA) app to manage inspections. No native app.

---

## 30. ACC-654 — incoming and outgoing photo uploads per property and lease (both sides)

Source: Kadro card **ACC-654** "Properties: incoming and outgoing photo uploads per property and lease" (ACC board, Backlog, priority medium, estimate 1 h, no spec or tech-spec written yet).

### 30.1 What the card asks for (verbatim substance)
- Attach **move-in (incoming)** and **move-out (outgoing)** photos to **a lease and a property**.
- Photos are **dated** and **grouped by room or unit**.
- Stored as **property documents**.
- Reason: needed for **deposit disputes**, see **IDE-1** on the Ideas board.

### 30.2 Side A — Accounting (ACC board, the card as it stands)
- Lives in the Accounting repo's property and lease area. Accounting owns accounts, ledger and invoices; lease and property records are the link point.
- Needs: lease records; property documents; photo grouping by room or unit; dates; move-in and move-out tagging.
- Deposit disputes are a money matter, so the photo record is evidence, not a liability decision (section 16 rule applies).

### 30.3 Side B — PropertyTrail (this product)
- The same need is already covered by **incoming and outgoing inspections** (sections 10.1 and 10.3), with **evidence** (section 14) and **inventory and check-in/check-out** (section 11).
- PropertyTrail's evidence model is stronger: SHA-256 hashes, permanent originals, audit trail, token-based tenant upload.
- Duplicate risk: two places holding move-in photos. Avoid by giving each evidence record one owner.

### 30.4 Proposed split (for the owner to confirm, decision D1)
| Concern | Owner | Notes |
|---|---|---|
| Lease, tenant, rent, deposit records | Accounting (ACC) | Existing ACC cards; deposit balances stay in accounting |
| Property, room and lease link | PropertyTrail (system of record for condition) | Lease referenced by ID; PropertyTrail does not hold deposit money |
| Move-in / move-out photos, dated, grouped by room | PropertyTrail | Incoming and outgoing inspections, evidence with hashes |
| Property documents view for ACC | Accounting reads via PropertyTrail API | No copy of photos in Accounting |
| IDE-1 deposit-dispute evidence | PropertyTrail produces an evidence pack; Accounting decides money | AI never decides liability |

Rule applied: a defect or feature in one product is not built in another. Accounting only links to PropertyTrail evidence through an API. This follows the fleet rule that products use the shared API rather than a local copy.

### 30.5 Data additions (PropertyTrail)
- `Lease` (external reference to the accounting lease ID, `organisation_id`, property, tenant, start and end dates, status).
- `Occupancy` links to `Lease` (long-term tenancy becomes a lease, short-term becomes an occupancy).
- Evidence context gains `lease_id`, `phase` (`incoming` | `outgoing` | `interim`), `room_id` or unit.
- Photos are uploaded through the signed-URL flow (section 4.3), hashed on receipt, never overwritten.

### 30.6 Acceptance (PT-019, lease photo phase)
1. Move-in photos attach to a lease and a property, dated, grouped by room or unit.
2. Move-out photos attach the same way and can be compared to move-in (section 10.3).
3. Photos appear as property documents in the property Documents tab and in the Leases tab (section 21.1).
4. Accounting can read the lease photo list through the PropertyTrail API; it stores no copy.
5. Tenant isolation: another organisation cannot list or fetch these photos (section 6 tests).
6. Evidence is never silently replaced; a corrected photo is a new record linked to the old.

### 30.7 Open questions
| # | Question |
|---|---|
| Q1 | Is "property documents" in ACC the same as PropertyTrail's Documents tab, or a separate store? |
| Q2 | Do deposit disputes need PropertyTrail evidence packs (section 13) before the rest of Phase 5? |
| Q3 | Is the IDE-1 card (Ideas board) the owner's sign-off for this scope? |

---

## 31. Change log
- v1.0: initial specification and wiki.
- v1.1: added ACC-654 (section 30), lease link in sections 8, 14, 21, 24, 26; open decisions in section 0. No v1.0 content removed.
