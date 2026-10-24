# How things move through NextTime: three example flows

Each flow says what works today, what is missing, and where each missing piece appears in
[the to-do list](proposals/next-steps.md) (items T1, T2 and so on). "Checked" means a script in `scripts/` runs it.

Who is who: **Priya** fills in a form on her phone. **Sam** is an organizer (group `timebank-organizer`), who works the
desk. **Dana** is in "Colorado Facilitators". Priya, Ola, Wen, Max and Pat live in Colorado (group "Colorado").

---

## 1. An offer, from a phone form to the desk

*Priya fills in a form: "I can drive people to appointments on weekends, ZIP 80202."*

| Step | What happens | State |
|------|--------------|-------|
| 1 | Priya submits a form on her phone. | **Not built.** There is no form yet (T1). |
| 2 | The submission becomes a row in `oc_timebank_layer_rows`, layer `needs_offers`, `source=form`, `publish=no`. Required fields are checked by the same rules as a hand-typed row (`RowValidator`). | The row storage and `source=form` exist; nothing writes them (T1). |
| 3 | Her ZIP, 80202, decides the chapter: ZIP prefix 802 is Colorado, so the Colorado chapter (the group "Colorado Facilitators") reviews it. Rows are never given a chapter by anyone's say-so; it is worked out from the ZIP each time. | Built and checked (`desk-smoke.sh`). |
| 4 | Sam and Dana both see the row on **Organizer Desk**. Dana, as facilitator of Colorado, sees public and chapter fields for Colorado rows only; Sam, as organizer, sees all fields. Contact details Priya gave never reach anyone outside the roles that may see them. If Dana leaves "Colorado Facilitators" she stops seeing Colorado rows at once. | Built and checked. |
| 5 | Sam presses **Assign to me**. The row is his (another organizer cannot take it), status `assigned`. He moves it to `in_progress` and `done` as he works. "Assigned to me" lists his rows; filter by source `form` or `manual` to tell phone submissions from hand entry. | Built and checked. **Only organizers can assign, not facilitators** (R5). |
| 6 | When the row is ready Sam publishes it, and it appears on the public map feed with its point rounded to about 1 km and only public fields. | Built and checked. |
| 7 | Priya hears back. | Not built (T4). Today the facilitator contacts her by the route she chose on the form. |

**Gap that matters:** a desk row is a *record for organizers*. It is not on the Request Board and nobody can volunteer
against it (T3).

## 2. A need, same route

*Wen fills in the same form: "I need a ride to dialysis on Tuesdays, ZIP 80202, urgency this week."*

Steps 1 to 7 are the same, with `need_or_offer = need`. What changes:

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
- **T6 Where do the hours come from?** Approving a claim *creates* hours for the volunteer. Ola, who got the help, is
  never charged. That suits a "recognize contributions" model. If NextTime should be a closed time bank
  (hours move from the person helped to the helper), the requester must be debited and spending limits applied.
- **T7 Per-category progress** on the personal dashboard (hours by category, claims waiting, rejected with reason) is not
  shown. The page shows the balance and ledger only; claims are listed on Submit Earnings.

---

## Where the data is, at each step

Everything is in the Nextcloud database (see [data-and-access.md](data-and-access.md)): desk rows in
`oc_timebank_layer_rows`, requests, offers and claims in their own tables, hours in `oc_timebank_balances` and
`oc_timebank_transactions`, claim notices in Nextcloud's own notifications table.
