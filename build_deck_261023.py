# MK Command fortnightly review deck — snapshot 23 Oct 2026 13:46
# Every staff figure comes from the staff pool (staffPoolAPI.php), overrides applied.
# Ownership is read from the MK Command access list (mkPortal.mk_command_access),
# which is a different grant from the KPI administrator tables — it is the right
# to run MK Command for a company.
import sys
sys.path.insert(0, r"C:\Users\User\.claude\skills\management-deck\assets")
from deckkit import *

SNAP = "Snapshot 23 Oct 2026 15:27"
POOL = "Staff figures are the staff pool (staffPoolAPI.php), which already leaves out the 21 people an override has removed."

# ---------------------------------------------------------------- figures ----
# pool: in-pool people only.  exempt: KPI-exempt among them.  excl: removed by an
# override and counted nowhere.  js_*: job spec versions for pool people only.
# memo_*: memos sent BY that company (sender's company), memo26 = 2026 YTD.
C = [
    dict(key=1,  name="Globinaco",    staff=319, gw=346, pool=665, exempt=2, excl=15,
         js_with=628, js_appr=608, js_vers=651, js_apprv=616, js_pend=17, js_rej=18,
         s_with=302, s_appr=282, g_with=326, g_appr=326, g_pend=0,
         md=264, md_m=6, md_d=256, md26=107, admins=8, lead="Vincent Ng Tung Hau",
         att_s=206, att_g=341,
         memo=9, memo26=9, memo_senders=4, memo_latest="21 Oct",
         memo_who="Vincent Ng (4), Dr. Brian Lim (3), Jeffrey Soo, Soo Chee Kiong."),
    dict(key=7,  name="Hotel Grandis", staff=150, gw=0, pool=150, exempt=1, excl=0,
         js_with=142, js_appr=136, js_vers=142, js_apprv=136, js_pend=6, js_rej=0,
         s_with=142, s_appr=136, g_with=0, g_appr=0, g_pend=0,
         md=958, md_m=712, md_d=246, md26=601, admins=5, lead="Michael Tan (QMR)",
         att_s=0, att_g=0,
         memo=3, memo26=3, memo_senders=2, memo_latest="16 Oct",
         memo_who="Natasha Beatta Gait (2) and Jet Mah Mun Keat."),
    dict(key=4,  name="Arus Sawit",   staff=73, gw=585, pool=658, exempt=1, excl=0,
         js_with=657, js_appr=643, js_vers=657, js_apprv=643, js_pend=14, js_rej=0,
         s_with=72, s_appr=58, g_with=585, g_appr=585, g_pend=0,
         md=25, md_m=19, md_d=6, md26=23, admins=3, lead="Asyraf bin Yahya",
         att_s=0, att_g=0,
         memo=1, memo26=1, memo_senders=1, memo_latest="21 Oct",
         memo_who="One, from Asyraf bin Yahya."),
    dict(key=8,  name="TP Group",     staff=65, gw=0, pool=65, exempt=2, excl=3,
         js_with=62, js_appr=61, js_vers=71, js_apprv=63, js_pend=1, js_rej=7,
         s_with=62, s_appr=61, g_with=0, g_appr=0, g_pend=0,
         md=0, md_m=0, md_d=0, md26=0, admins=6, lead="Razali Mohd Ali",
         att_s=0, att_g=0,
         memo=0, memo26=0, memo_senders=0, memo_latest="—",
         memo_who="Nothing sent. 65 staff and no broadcast has ever gone out."),
    dict(key=3,  name="AMC",          staff=61, gw=0, pool=61, exempt=1, excl=0,
         js_with=55, js_appr=42, js_vers=55, js_apprv=42, js_pend=13, js_rej=0,
         s_with=55, s_appr=42, g_with=0, g_appr=0, g_pend=0,
         md=0, md_m=0, md_d=0, md26=0, admins=2, lead="Dr. Cannye Chung",
         att_s=0, att_g=0,
         memo=6, memo26=4, memo_senders=2, memo_latest="22 Oct",
         memo_who="Dr. Pan (3) and Aqilla Dalinte (1) — the most recent in the group."),
    dict(key=2,  name="Likas Square", staff=51, gw=0, pool=51, exempt=0, excl=3,
         js_with=40, js_appr=5, js_vers=40, js_apprv=5, js_pend=32, js_rej=3,
         s_with=40, s_appr=5, g_with=0, g_appr=0, g_pend=0,
         md=6, md_m=4, md_d=2, md26=5, admins=4, lead="Eliab Lo Shen Penng",
         att_s=0, att_g=0,
         memo=0, memo26=0, memo_senders=0, memo_latest="—",
         memo_who="Nothing sent."),
    dict(key=16, name="Trillium",     staff=20, gw=0, pool=20, exempt=2, excl=0,
         js_with=14, js_appr=14, js_vers=14, js_apprv=14, js_pend=0, js_rej=0,
         s_with=14, s_appr=14, g_with=0, g_appr=0, g_pend=0,
         md=0, md_m=0, md_d=0, md26=0, admins=0, lead="— none —",
         att_s=0, att_g=0,
         memo=0, memo26=0, memo_senders=0, memo_latest="—",
         memo_who=""),
]
S = lambda f: sum(c[f] for c in C)
T = dict(staff=S("staff"), gw=S("gw"), pool=S("pool"), exempt=S("exempt"), excl=S("excl"),
         js_with=S("js_with"), js_appr=S("js_appr"), js_vers=S("js_vers"),
         js_apprv=S("js_apprv"), js_pend=S("js_pend"), js_rej=S("js_rej"),
         s_appr=S("s_appr"), g_appr=S("g_appr"), g_pend=S("g_pend"),
         md=S("md"), md_m=S("md_m"), md_d=S("md_d"), md26=S("md26"), admins=S("admins"),
         memo=S("memo"), memo26=S("memo26"))
MEMO = dict(total=103, y2022=31, y2023=31, y2024=16, y2025=5, y2026=19, undated=1,
            senders26=9, ceo=76, untagged=84, untagged26=2)
MD_YEARS = [("2023", 0, 1, 1), ("2024", 1, 146, 148), ("2025", 260, 108, 368),
            ("2026 YTD", 480, 255, 736)]

def f(n):  return f"{n:,}"
def P(a, b): return "—" if not b else f"{round(100.0*a/b)}%"

# ---------------------------------------------------------------- helpers ----
def grid7(sl, panels, y0=Inches(1.80)):
    """4x2 grid — the six companies plus Trillium on one page."""
    pw, ph, gx, gy = Inches(2.86), Inches(2.42), Inches(0.235), Inches(0.235)
    for i, p in enumerate(panels[:8]):
        x = X0 + (i % 4) * (pw + gx)
        y = y0 + (i // 4) * (ph + gy)
        rect(sl, x, y, pw, ph, fill=WHITE, line=BORDER)
        rect(sl, x, y, pw, Inches(0.045), fill=p["accent"])
        text(sl, x + Inches(0.20), y + Inches(0.15), pw - Inches(0.40), Inches(0.26),
             [(p["name"], 11.5, True, DARKNAVY, HEAD)])
        text(sl, x + Inches(0.20), y + Inches(0.43), Inches(1.6), Inches(0.5),
             [(p["big"], 24, True, p["accent"], HEAD)])
        text(sl, x + Inches(0.20), y + Inches(0.88), Inches(2.3), Inches(0.22),
             [(p["biglabel"], 8, True, GREY, BODY)])
        ly = y + Inches(1.10)
        for (lab, val, col) in p.get("lines", [])[:4]:
            text(sl, x + Inches(0.20), ly, Inches(1.6), Inches(0.21),
                 [(lab, 8.5, False, GREY, BODY)])
            text(sl, x + Inches(1.80), ly - Inches(0.015), Inches(0.86), Inches(0.22),
                 [(val, 9.5, True, col, BODY)], align=PP_ALIGN.RIGHT)
            ly += Inches(0.19)
        rect(sl, x + Inches(0.20), y + Inches(1.90), pw - Inches(0.40), Emu(9525), fill=BORDER)
        text(sl, x + Inches(0.20), y + Inches(1.98), pw - Inches(0.40), Inches(0.40),
             [(p["verdict"], 8, False, RGBColor(0x3A, 0x42, 0x5C), BODY)], line_spacing=1.10)

def tiles(sl, specs, y=Inches(1.78), h=Inches(1.95)):
    for i, (v, lab, note, acc) in enumerate(specs):
        kpi_tile(sl, X0 + i * (TW + GP), y, TW, h, v, lab, note, accent=acc)

prs = new_deck()

# ---------------------------------------------------------------- 1 title ----
s = add_slide(prs)
rect(s, 0, 0, W, H, fill=DARKNAVY)
rect(s, 0, 0, Inches(0.55), H, fill=NAVY)
text(s, Inches(1.15), Inches(1.95), Inches(11), Inches(0.35),
     [("MANAGEMENT REVIEW", 12.5, True, AMBER, BODY)])
text(s, Inches(1.15), Inches(2.42), Inches(11), Inches(1.0),
     [("MK Command", 46, True, WHITE, HEAD)])
rect(s, Inches(1.15), Inches(3.52), Inches(2.2), Emu(19050), fill=AMBER)
text(s, Inches(1.15), Inches(3.86), Inches(11), Inches(0.4),
     [("Ownership  ·  Staff Headcount  ·  Job Specs  ·  Merit / Demerit  ·  Memo Activity",
       14, False, MUTED, BODY)])
text(s, Inches(1.15), Inches(4.60), Inches(11.2), Inches(0.9),
     [(f"7 companies  ·  {f(T['staff'])} staff  ·  {f(T['gw'])} general workers  ·  "
       f"{f(T['js_vers'])} job specs  ·  {f(T['md'])} merit/demerit records",
       13, False, WHITE, BODY)])
text(s, Inches(1.15), Inches(6.30), Inches(11), Inches(0.3),
     [(SNAP + "  ·  every staff figure on the staff pool basis, Trillium included",
       10, False, MUTED, BODY)])

# --------------------------------------------------------------- 2 agenda ----
s = add_slide(prs)
header(s, "How This Review Works", "Five topics. Each is shown for the group first, then across the seven companies.",
       kicker="Agenda")
items = [
    ("1", "Ownership",       "Who can run the training system in each company", "13", "PEOPLE WITH ACCESS"),
    ("2", "Staff Headcount", "Who is on the books across seven companies", f(T["pool"]), "TOTAL STAFF"),
    ("3", "Job Specs",       "How many people hold an approved job spec", P(T["s_appr"], T["staff"]), "OF STAFF APPROVED"),
    ("4", "Merit / Demerit", "Who is using the recognition tool", f(T["md"]), "RECORDS ISSUED"),
    ("5", "Memo Activity",   "Which companies are broadcasting, and who sends", str(MEMO["y2026"]), "SENT IN 2026"),
]
y = Inches(1.80)
for (n, t, d, v, vl) in items:
    rect(s, X0, y, CW, Inches(0.92), fill=PAPER, line=BORDER)
    rect(s, X0, y, Inches(0.055), Inches(0.92), fill=NAVY)
    text(s, X0 + Inches(0.30), y + Inches(0.26), Inches(0.5), Inches(0.4),
         [(n, 20, True, AMBER, HEAD)])
    text(s, X0 + Inches(0.86), y + Inches(0.17), Inches(4.2), Inches(0.3),
         [(t, 14, True, DARKNAVY, HEAD)])
    text(s, X0 + Inches(0.86), y + Inches(0.52), Inches(6.0), Inches(0.3),
         [(d, 10.5, False, GREY, BODY)])
    text(s, X0 + Inches(8.0), y + Inches(0.20), Inches(2.6), Inches(0.42),
         [(v, 20, True, NAVY, HEAD)], align=PP_ALIGN.RIGHT)
    text(s, X0 + Inches(8.0), y + Inches(0.62), Inches(2.6), Inches(0.24),
         [(vl, 8.5, True, GREY, BODY)], align=PP_ALIGN.RIGHT)
    y += Inches(1.00)
source(s, "All figures re-queried " + SNAP.lower().replace("snapshot ", "") + ". " + POOL)

# ------------------------------------------------------------ T1 ownership ----
# Read from the MK Command access list (mkPortal.mk_command_access), active
# grants only, 23 Oct 2026. all_companies = 1 is a whole-group grant; everybody
# else is scoped to the company in assigned_companies.
WHOLE_GROUP = [
    ("Chua Shin Fun @ Kevin Chua", "IT Manager",     "GLOBINACO"),
    ("Siti Khadijah Binti Kabul",  "Web Programmer", "GLOBINACO"),
    ("Soo Zhao Yuan Nathan",       "Programmer",     "GLOBINACO"),
    ("Dr. Pan",                    "HRM",            "AMC"),
]
# Lead first — that company's HR or admin lead, which is the name to go to
# first. The access list itself does not rank one grant above another.
ADMINS = {
    1:  ["Vincent Ng Tung Hau", "Bella Thien Wen Jing", "Elien Kong Xiao Lian", "John Lim Yun Vui"],
    7:  ["Natasha Beatta Gait", "Michael Tan (QMR)"],
    4:  ["Asyraf bin Yahya", "Lim Chen Eng", "Fabian Lim Chin Wen"],
    8:  ["Esther Olivia Paul", "Felicia Lai Hung Len", "Patrick Ng Lie Seong"],
    3:  ["Aqilla Dalinte"],
    2:  ["Fionne Foo Ka Kyun"],
}
# Trillium's people are stored under Globinaco (comp_id 1), so Globinaco's
# administrators act for them. It holds no grant of its own and needs none.
VIA = {16: 1}
ADMINS[16] = ADMINS[1]
N_WHOLE  = len(WHOLE_GROUP)
N_ADMIN  = sum(len(v) for k, v in ADMINS.items() if k not in VIA)
N_COVER  = sum(1 for k, v in ADMINS.items() if v and k not in VIA)

divider(prs, "Ownership", "Who can run this in each company", "TOPIC ONE")

s = add_slide(prs)
header(s, "Ownership — Group Summary",
       f"{N_WHOLE} with whole-group access and {N_ADMIN} company administrators across "
       f"{N_COVER} companies. Each list leads with that company's owner — the name to go to first.",
       kicker="Topic 1 · Ownership")

text(s, X0, Inches(1.72), Inches(6.0), Inches(0.26),
     [("Whole group access", 13, True, NAVY, HEAD),
      ("   —  can act on any company", 10.5, False, GREY, BODY)])
for i, (nm, pos, comp) in enumerate(WHOLE_GROUP):
    x = X0 + i * (TW + GP)
    rect(s, x, Inches(2.06), TW, Inches(0.88), fill=PAPER, line=BORDER)
    rect(s, x, Inches(2.06), Inches(0.055), Inches(0.88), fill=NAVY)
    text(s, x + Inches(0.22), Inches(2.17), TW - Inches(0.40), Inches(0.24),
         [(nm, 10.5, True, DARKNAVY, HEAD)])
    text(s, x + Inches(0.22), Inches(2.45), TW - Inches(0.40), Inches(0.22),
         [(pos, 8.5, False, GREY, BODY)])
    text(s, x + Inches(0.22), Inches(2.68), TW - Inches(0.40), Inches(0.20),
         [(comp, 8, True, NAVY, BODY)])

text(s, X0, Inches(3.16), Inches(7.0), Inches(0.26),
     [("Company administrators", 13, True, NAVY, HEAD),
      ("   —  can act on their own company only", 10.5, False, GREY, BODY)])
pw, ph, gx, gy = Inches(2.86), Inches(1.52), Inches(0.235), Inches(0.20)
for i, c in enumerate(C):
    names = ADMINS.get(c["key"], [])
    x = X0 + (i % 4) * (pw + gx)
    y = Inches(3.46) + (i // 4) * (ph + gy)
    rect(s, x, y, pw, ph, fill=WHITE, line=BORDER)
    rect(s, x, y, pw, Inches(0.045), fill=NAVY if names else RED)
    title_runs = [(c["name"], 11.5, True, DARKNAVY, HEAD)]
    if c["key"] in VIA:
        title_runs.append(("  via Globinaco", 7.5, False, GREY, BODY))
    text(s, x + Inches(0.20), y + Inches(0.15), Inches(2.0), Inches(0.26), title_runs)
    text(s, x + Inches(2.10), y + Inches(0.13), Inches(0.56), Inches(0.28),
         [(str(len(names)) if names else "—", 13, True, NAVY if names else RED, HEAD)],
         align=PP_ALIGN.RIGHT)
    ny = y + Inches(0.48)
    if not names:
        text(s, x + Inches(0.20), ny, pw - Inches(0.40), Inches(0.44),
             [("Nobody can act on this company.", 8.5, False, RED, BODY)], line_spacing=1.12)
    for j, nm in enumerate(names):
        runs = [(nm, 9, True, BLACK, BODY)]
        if j == 0:
            runs.append(("  OWNER", 7, True, AMBER, BODY))
        text(s, x + Inches(0.20), ny, pw - Inches(0.34), Inches(0.22), runs)
        ny += Inches(0.225)

source(s, "Read from the MK Command access list on 23 Oct 2026, active grants only. Whole group = a grant "
          "that is not tied to one company. OWNER marks the name to go to first — that company's HR or "
          "admin lead; the list itself does not rank one grant above another. Trillium's people are "
          "recorded under Globinaco, so Globinaco's administrators act for them and it holds no grant of "
          "its own. A further grant covers City Skill, which is not one of the companies in this review.")

# ------------------------------------------------------------ T2 headcount ----
divider(prs, "Staff Headcount", "Who is on the books across seven companies", "TOPIC TWO")

s = add_slide(prs)
header(s, "Staff Headcount — Group Summary",
       f"{f(T['pool'])} people on the books: {f(T['staff'])} staff and {f(T['gw'])} general workers.",
       kicker="Topic 2 · Group Summary")
tiles(s, [
    (f(T["staff"]), "Staff", "Across all seven companies, Trillium included.", NAVY),
    (f(T["gw"]), "General workers", f"{C[0]['gw']} at Globinaco and {C[2]['gw']} at Arus Sawit.", AMBER),
    (f(T["pool"]), "Total Staff", "Staff and general workers together.", NAVY),
    (str(T["exempt"]), "Exempt from KPI", "Counted in the headcount, excused from scoring.", GREY),
])
rows = []
for c in C:
    rows.append([c["name"], f(c["staff"]), str(c["exempt"]) if c["exempt"] else "—",
                 f(c["gw"]) if c["gw"] else "—", f(c["pool"])])
rows.append(["Total", f(T["staff"]), str(T["exempt"]), f(T["gw"]), f(T["pool"])])
table(s, X0, Inches(3.98), Inches(7.55),
      ["Company", "Staff", "Exempt from KPI", "General workers", "Total Staff"],
      rows, [2.3, 1.2, 1.5, 1.6, 1.5], total_row=True, row_h=Inches(0.30), fsize=10)
callout(s, Inches(8.52), Inches(3.98), Inches(4.2), Inches(1.32),
        f"{T['excl']} people are not counted",
        f"Removed from the headcount by name — {C[0]['excl']} at Globinaco, "
        f"{C[5]['excl']} at Likas Square, {C[3]['excl']} at TP Group.", accent=GREY)
callout(s, Inches(8.52), Inches(5.46), Inches(4.2), Inches(1.32),
        "Trillium joins the pack", f"{C[6]['staff']} staff, {C[6]['exempt']} of them exempt from scoring. "
        "They were inside Globinaco's figures before and are now reported on their own.", accent=NAVY)
source(s, "Staff pool basis (staffPoolAPI.php), which every slide in this pack now follows. "
          "In-scope people only; the 21 removed by an override are excluded from every figure here. "
          "Exempt people are counted in the headcount.")

s = add_slide(prs)
header(s, "Staff Headcount — By Company",
       "People on the books at each company, staff and general workers together.",
       kicker="Topic 2 · By Company")
panels = []
for c in C:
    att = ""
    if c["att_s"] or c["att_g"]:
        att = f" {c['att_s']} staff and {c['att_g']} general workers clocked in during October."
    elif c["key"] == 4:
        att = " No general worker here has ever recorded attendance."
    panels.append(dict(
        name=c["name"], big=f(c["pool"]), biglabel="TOTAL STAFF",
        accent=AMBER if c["key"] in (1, 4) else NAVY,
        lines=[("Staff", f(c["staff"]), BLACK),
               ("General workers", f(c["gw"]) if c["gw"] else "—", RED if c["key"] == 4 else BLACK),
               ("Exempt from KPI", str(c["exempt"]) if c["exempt"] else "—", GREY),
               ("Share of group", P(c["pool"], T["pool"]), BLACK)],
        verdict=(f"{f(c['staff'])} staff" + (f" and {f(c['gw'])} general workers." if c["gw"] else ", no general workers.") + att)))
grid7(s, panels)
source(s, "Same staff pool basis as the group summary. Share of group is of the "
          f"{f(T['pool'])} people on the books. October attendance is check-ins recorded by the worker themselves.")

# ------------------------------------------------------------ T3 job specs ----
divider(prs, "Job Specs", "How many people hold an approved job spec", "TOPIC THREE")

s = add_slide(prs)
header(s, "Job Specs — Group Summary",
       f"{P(T['s_appr'], T['staff'])} of staff and {P(T['g_appr'], T['gw'])} of general workers hold an approved job spec. "
       f"Both worker populations were cleared this week.",
       kicker="Topic 3 · Group Summary")
tiles(s, [
    (P(T["s_appr"], T["staff"]), "Of staff approved",
     f"{f(T['s_appr'])} of {f(T['staff'])} staff hold an approved job spec.", GREEN),
    (P(T["g_appr"], T["gw"]), "Of general workers",
     f"{f(T['g_appr'])} of {f(T['gw'])}. The 20 without one are all at Globinaco.", GREEN),
    (f(T["js_pend"]), "Waiting for approval",
     f"Down from 412 at the last review. {C[5]['js_pend']} of them are at Likas Square.", GREEN),
    (f(T["js_rej"]), "Rejected, need reworking",
     "Sent back and not yet resubmitted.", GREY),
])
rows = []
for c in C:
    pc = P(c["js_appr"], c["pool"])
    r = c["js_appr"] / max(c["pool"], 1)
    col = GREEN if r >= 0.6 else (RED if r < 0.25 else AMBER)
    rows.append(([c["name"], f(c["pool"]), f(c["js_with"]), f(c["js_appr"]),
                  f(c["js_pend"]) if c["js_pend"] else "—", pc], {5: col}))
rows.append(["Total", f(T["pool"]), f(T["js_with"]), f(T["js_appr"]), f(T["js_pend"]),
             P(T["js_appr"], T["pool"])])
table(s, X0, Inches(3.98), Inches(7.55),
      ["Company", "Total Staff", "Has a job spec", "Approved", "Pending", "Approved %"],
      rows, [2.1, 1.4, 1.5, 1.2, 1.2, 1.3], total_row=True, row_h=Inches(0.30), fsize=10)
callout(s, Inches(8.52), Inches(3.98), Inches(4.2), Inches(1.32),
        "The worker gap has closed",
        f"{f(C[0]['g_appr'])} specs approved at Globinaco and {f(C[2]['g_appr'])} issued at Arus Sawit, "
        "which had none at the last review.", accent=GREEN)
callout(s, Inches(8.52), Inches(5.46), Inches(4.2), Inches(1.32),
        "What is left is staff-side",
        f"All {f(T['js_pend'])} waiting are staff, {C[5]['js_pend']} of them at Likas Square — the only "
        f"company still under half approved.", accent=AMBER)
source(s, "Job spec versions counted only for people in the staff pool, so these figures tally with the "
          "headcount slides. Approved % is of total staff, not of versions on file: a person with no job "
          "spec counts against their company.")

s = add_slide(prs)
header(s, "Job Specs — By Company",
       "Approved job specs as a share of everyone on the books at that company.",
       kicker="Topic 3 · By Company")
panels = []
for c in C:
    r = c["js_appr"] / max(c["pool"], 1)
    acc = GREEN if r >= 0.6 else (AMBER if r >= 0.25 else RED)
    if c["gw"]:
        verdict = (f"Staff {P(c['s_appr'], c['staff'])} approved, general workers "
                   f"{P(c['g_appr'], c['gw'])}. " +
                   ("The worker queue cleared this week." if c["key"] == 1 else
                    f"All {f(c['gw'])} workers were issued a spec this week."))
    else:
        verdict = (f"{f(c['js_appr'])} of {f(c['pool'])} staff approved" +
                   (f", {f(c['js_pend'])} still waiting." if c["js_pend"] else ", nothing outstanding."))
    panels.append(dict(
        name=c["name"], big=P(c["js_appr"], c["pool"]), biglabel="OF STAFF APPROVED", accent=acc,
        lines=[("Total staff", f(c["pool"]), BLACK),
               ("Has a job spec", f(c["js_with"]), BLACK),
               ("Approved", f(c["js_appr"]), GREEN if c["js_appr"] else RED),
               ("Pending", f(c["js_pend"]) if c["js_pend"] else "—", AMBER if c["js_pend"] else GREY)],
        verdict=verdict))
grid7(s, panels)
source(s, "Approved % is approved people over total staff on the staff pool basis — the same population as "
          "the headcount slides. A person with more than one version counts once.")

# -------------------------------------------------------- T4 merit/demerit ----
divider(prs, "Merit / Demerit", "Who is using the recognition tool", "TOPIC FOUR")

s = add_slide(prs)
header(s, "Merit / Demerit — Group Summary",
       f"{f(T['md'])} records issued to date, and 2026 is already double any year before it.",
       kicker="Topic 4 · Group Summary")
tiles(s, [
    (f(T["md"]), "Records issued", "2023 to date. Up 46 since the last review.", NAVY),
    (P(T["md_m"], T["md"]), "Merit share", f"{f(T['md_m'])} merit against {f(T['md_d'])} demerit.", NAVY),
    (f(T["md26"]), "Issued in 2026", "Against 368 in the whole of 2025.", GREEN),
    (P(C[1]["md"], T["md"]), "From one company", f"{f(C[1]['md'])} of {f(T['md'])} records are Hotel Grandis's.", AMBER),
])
rows = [[y, f(m), f(d), f(t)] for (y, m, d, t) in MD_YEARS]
rows.append(["Total", f(T["md_m"]), f(T["md_d"]), f(T["md"])])
table(s, X0, Inches(4.02), Inches(5.6), ["Year", "Merit", "Demerit", "Total"],
      rows, [2.0, 1.2, 1.2, 1.2], total_row=True, fsize=10)
callout(s, Inches(6.60), Inches(4.02), Inches(6.12), Inches(1.30),
        "Three companies have never issued one",
        "AMC, TP Group and Trillium have no records at all. Between them that is "
        f"{f(C[3]['pool'] + C[4]['pool'] + C[6]['pool'])} people whose recognition is not being recorded anywhere.", accent=RED)
callout(s, Inches(6.60), Inches(5.50), Inches(6.12), Inches(1.30),
        "Two companies, two habits",
        f"Hotel Grandis is merit-led ({P(C[1]['md_m'], C[1]['md'])} merit). Globinaco is almost entirely "
        f"demerit ({C[0]['md_m']} merit out of {f(C[0]['md'])}). The same tool is being used for opposite purposes.", accent=AMBER)
source(s, "All records on file, excluding deleted ones. Two records carry no merit/demerit type and are in "
          "the totals but neither column. A record is counted for the company of the person it was issued to.")

s = add_slide(prs)
header(s, "Merit / Demerit — By Company",
       "Hotel Grandis carries three quarters of group volume; three companies have issued none.",
       kicker="Topic 4 · By Company")
panels = []
for c in C:
    if c["md"]:
        acc = GREEN if c["md_m"] > c["md_d"] else RED
        verdict = (f"{f(c['md'])} records, {f(c['md26'])} of them in 2026. " +
                   ("Merit-led — the tool used as intended." if c["md_m"] > c["md_d"]
                    else "Almost entirely demerit."))
        big = P(c["md_m"], c["md"])
    else:
        acc, big = GREY, "—"
        verdict = f"No records at all. {f(c['pool'])} people and nothing recognised or recorded."
    panels.append(dict(
        name=c["name"], big=big, biglabel="MERIT SHARE", accent=acc,
        lines=[("Merit", f(c["md_m"]) if c["md"] else "—", GREEN if c["md_m"] else GREY),
               ("Demerit", f(c["md_d"]) if c["md"] else "—", RED if c["md_d"] else GREY),
               ("Total", f(c["md"]) if c["md"] else "—", BLACK),
               ("Issued in 2026", f(c["md26"]) if c["md26"] else "—", BLACK)],
        verdict=verdict))
grid7(s, panels)
source(s, "Merit share is merit records over all records for that company. People counted on the staff pool "
          "basis; records are counted wherever the person sits today.")

# ----------------------------------------------------------------- T5 memos ----
divider(prs, "Memo Activity", "Which companies are broadcasting, and who sends", "TOPIC FIVE")

s = add_slide(prs)
header(s, "Memo Activity — Group Summary",
       f"{MEMO['y2026']} memos so far in 2026 from {MEMO['senders26']} different people — the tool is no longer one person's.",
       kicker="Topic 5 · Group Summary")
tiles(s, [
    (str(MEMO["total"]), "Memos on file", "2022 to date, all companies.", NAVY),
    (str(MEMO["y2026"]), "Issued in 2026", "Against 5 in the whole of 2025, and 9 at the last review.", GREEN),
    (str(MEMO["senders26"]), "Senders this year", "Against 11 names in the entire history of the tool.", GREEN),
    (P(MEMO["ceo"], MEMO["total"]), "Historic concentration",
     f"{MEMO['ceo']} of {MEMO['total']} memos came from the Chief Executive Officer — none of them this year.", AMBER),
])
rows = [["2022", "31"], ["2023", "32"], ["2024", "16"], ["2025", "5"], ["2026 YTD", "19"]]
rows.append(["Total", str(MEMO["total"])])
table(s, X0, Inches(4.02), Inches(3.6), ["Year", "Memos"], rows, [2.0, 1.3],
      total_row=True, fsize=10)
callout(s, Inches(4.60), Inches(4.02), Inches(8.12), Inches(1.30),
        "The habit has spread",
        "Vincent Ng (4), Dr. Pan (3) and Dr. Brian Lim (3) lead 2026, with six more senders behind them. "
        "Every memo this year came from somebody other than the Chief Executive.", accent=GREEN)
callout(s, Inches(4.60), Inches(5.50), Inches(8.12), Inches(1.30),
        "Sender is still free text",
        "Names are typed, not picked, so one person appears under two spellings. Worth fixing before this "
        "becomes a measure anybody is held to.", accent=AMBER)
source(s, "All memos on file, excluding deleted ones. Dates are stored as text in two different formats; "
          "one is written 27/06/23 and is counted in 2023, the year it was uploaded. Identities grouped by "
          "hand across spellings.")

s = add_slide(prs)
header(s, "Memo Activity — By Company",
       f"Four companies have sent a memo this year. Three have never sent one.",
       kicker="Topic 5 · By Company")
panels = []
for c in C:
    acc = GREEN if c["memo26"] >= 3 else (AMBER if c["memo26"] else GREY)
    panels.append(dict(
        name=c["name"], big=str(c["memo26"]) if c["memo26"] else "—", biglabel="SENT IN 2026",
        accent=acc,
        lines=[("Unique senders", str(c["memo_senders"]) if c["memo_senders"] else "—", BLACK),
               ("Latest", c["memo_latest"], GREY)],
        verdict=c["memo_who"]))
grid7(s, panels)
source(s, "A memo is counted for the company of the person who sent it. The 84 memos issued before this year "
          f"carry no company and are not on this slide, so these {T['memo']} are the memos that can be placed — "
          f"{T['memo26']} of them sent in 2026, plus {MEMO['untagged26']} more this year that carry no company. "
          "A memo goes to the whole group, not to one company.")

OUT = r"C:\Users\User\Desktop\Work\mkCommand-1\MK_Command_meeting_slides_261023.pptx"
# A path given on the command line wins, so the deck can be staged elsewhere
# while the real file is open in PowerPoint.
if len(sys.argv) > 1:
    OUT = sys.argv[1]
save(prs, OUT)
verify(OUT, stale=["729", "954", "1,682", "745", "941 versions", "Total versions", "28%",
                   "1,207", "93 memos", "Total in scope", "PEOPLE IN SCOPE",
                   "This cycle's asks", "Scoreboard", "1,671", "1,243",
                   "Staff headcount —", "Job specs —", "Memo activity —"])
print("built", OUT)
