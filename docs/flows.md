# How things move through NextTime: three example flows

Each flow says what works today, what is missing, and where each missing piece appears in
[the to-do list](proposals/next-steps.md) (items T1, T2 and so on). "Checked" means a script in `scripts/` runs it.

Who is who: **Priya** fills in a form on her phone. **Sam** is an organizer (group `timebank-organizer`). **Dana** is in "Colorado Facilitators". Priya, Ola, Wen, Max and Pat live in Colorado (group "Colorado").

---

## 1. An offer, from a phone form to the desk

*Priya fills in a form on her phone: "I can drive people to appointments on weekends, ZIP 80202."* The form is a
Nextcloud Form (made in the Forms app) with questions named after the desk fields.

| Step | What happens | State |
|------|--------------|-------|
| 1 | Priya submits the form. Nextcloud Forms stores her answers (and her account, unless the form is anonymous). | Forms app. |
| 2 | Within five minutes NextTime's background job (or someone pressing **Check now**) finds the new submission. It becomes a row in the `needs_offers` layer with `source = form`, **unpublished**, and the ID `NO-F3-17` (submission 17 of form 3). Hand-typed rows are `NO-M...`, so the ID alone tells where a row came from. Each submission is imported once. | Built and checked (`intake-smoke.sh`). |
| 3 | The row has to pass the same rules as a hand-typed one. If not (say an unknown category), it is logged on the **Form Intake** page as rejected, with the reason, and is retried only when an organizer presses **Look again at rejected**. | Built and checked. |
| 4 | Her ZIP, 80202, decides the chapter: Colorado, because the group "Colorado Facilitators" exists. Worked out from the ZIP each time. | Built and checked. |
| 5 | Dana (in "Colorado Facilitators") and Sam (organizer) see it on **Organizer Desk**. Dana sees public and chapter fields; the contact detail Priya gave is organizer-only, so only Sam sees it. If Dana leaves the facilitator group she stops seeing Colorado rows at once. | Built and checked. |
| 6 | Dana presses **Assign to me**, moves it to `in_progress`, and when it is ready presses **Publish**; it joins the public map feed (public fields, point rounded to about 1 km). Sam can do the same anywhere and can release a row whose holder has lost access. | Built and checked. |
| 7 | Priya hears back. | Not built (T4). |

**Gap that matters:** a desk row is a *record for organizers*. It is not on the Request Board and nobody can volunteer
against it (T3). And a facilitator working a row cannot see the contact detail (R5b).

## 2. A need, same route

*Wen submits the same form: "I need a ride to dialysis on Tuesdays, ZIP 80202, urgency this week."*

Steps 1 to 7 are the same, with `need_or_offer = need`, and the ID is `NO-F3-18`. What changes:

- The `urgency` field sorts it on Dana's desk.
- The desk is where a facilitator **matches** a need with an offer. Today that is by eye and by phone; there is no
  suggestion list (T5).
- To let neighbours volunteer, the need must also become a **request** on the Request Board. Today someone has to
  retype it there (T3). Once it is a request, the lifecycle in flow 3 applies.

## 3. A job done by three people, hours approved, balance updated

*Ola posts a request: "Help move a couch, 2 hours each." Wen and Max offer. Pat (volunteer) also offers.*

| Step | What happens | State |
|------|--------------|-------|
| 1 | Ola posts the request (category, hours budget, optional map point). | Built, checked. |
| 2 | Wen, Max and Pat each offer, with the hours they would contribute. | Built, checked. |
| 3 | Ola accepts all three. The request becomes **in progress**. Each accepted volunteer will be credited their own hours (not split). | Built, checked. |
| 4 | The job is done. Ola presses **Mark complete**. Every accepted volunteer is marked completed. **No hours move yet.** | Built, checked. |
| 5 | Each volunteer opens **Submit Earnings** and claims their hours with a short description. The category's earn rate applies (for example 1.2 hours per hour of home repair). | Built, checked. The claim is **not linked to the request** and nobody else confirms the hours (T2). |
| 6 | Each claim is **pending**. The people who may decide on it are told in Nextcloud notifications, in this order: the facilitators of the claimant's chapter, else the fallback group (GSUS), else admins. The notice names who, hours and category, never the description. | Built, checked (`approver-smoke.sh`). |
| 7 | Dana opens **Approvals & Votes**, sees only the claims she may decide ("as facilitator of Colorado"), and **approves**, **rejects** (a reason is required) or **sends to a community vote**. Nobody decides on their own claim. | Built, checked. |
| 8 | Approval credits the volunteer's balance and writes an entry in the public ledger naming the work. Wen's **My Balance** page now shows the new balance and the entry in **My Transaction History**. | Built, checked. |
| 9 | If Dana is reassigned out of the facilitator group, she can no longer approve, and no longer sees claims, on her next click. | Built, checked. |
| 10 | If a claim waits 3 days, the same people are reminded; after 7 days the admins are told too. | Built (hourly Nextcloud background job; not in the browser test). |

**Gaps that matter:**
- **T2 Link claims to the job.** Today a volunteer can claim any hours in any category, with no tie to a request or to
  the co-workers. The approver cannot see "Wen, Max and Pat all said 2 hours".
- **T6 Where do the hours come from?** *Decided for now:* approving a claim creates hours, and Ola, who got the help,
  is not charged. How hours are spent comes later, and the end system is expected to sit between a fully closed 1:1
  exchange and purely counted earnings.
- **T7 Per-category progress** on the personal dashboard (hours by category, claims waiting, rejected with reason) is not
  shown. The page shows the balance and ledger only; claims are listed on Submit Earnings.

---

## Where the data is, at each step

Everything is in the Nextcloud database (see [data-and-access.md](data-and-access.md)): desk rows in
`oc_timebank_layer_rows`, requests, offers and claims in their own tables, hours in `oc_timebank_balances` and
`oc_timebank_transactions`, claim notices in Nextcloud's own notifications table.
