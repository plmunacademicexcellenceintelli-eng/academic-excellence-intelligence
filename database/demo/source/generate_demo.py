#!/usr/bin/env python3
"""AEI demonstration data generator (fictional students, BACOM / BSIT / BSBA).

Deterministic: the same output on every run (fixed random seed per student).
Inputs : curricula.json (transcribed from the provided curriculum checklists)
Outputs: demo.json (students, records, activities, accounts) - consumed by build_sql.py and the verification step.

Rules followed
* Subjects, codes, titles and units come ONLY from curricula.json. NSTP subjects (0 units) are not stored: the system
  requires 1-12 units per record, and NSTP is excluded from the GWA anyway.
* Records exist only for COMPLETED terms. Today (Nov 2026) is AY 2026-2027 1st Semester, which is in progress, so
  the latest graded term is AY 2025-2026 2nd Semester.
    4th year (entered AY 2023-2024): curriculum 1-1 .. 3-2 in terms T1 .. T6
    3rd year (entered AY 2024-2025): curriculum 1-1 .. 2-2 in terms T3 .. T6
    2nd year (entered AY 2025-2026): curriculum 1-1 .. 1-2 in terms T5 .. T6
    1st year (entered AY 2026-2027): no completed term yet
* Grades use the PLMun-style 1.00-3.00 scale in 0.25 steps; 5.00 = failed. Each student's grades follow a
  profile (high, honors, average, declining, improving, incomplete) with small subject-to-subject variation.
"""
import json, random, hashlib, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
CUR = json.load(open(os.path.join(HERE, 'curricula.json')))
GRID = [1.00, 1.25, 1.50, 1.75, 2.00, 2.25, 2.50, 2.75, 3.00]
TERMS = [('T1', '2023-2024', '1st Semester'), ('T2', '2023-2024', '2nd Semester'), ('T3', '2024-2025', '1st Semester'),
         ('T4', '2024-2025', '2nd Semester'), ('T5', '2025-2026', '1st Semester'), ('T6', '2025-2026', '2nd Semester'),
         ('T7', '2026-2027', '1st Semester')]
CUR_ORDER = ['1-1', '1-2', '2-1', '2-2', '3-1', '3-2', '4-1', '4-2']
FIRST_TERM = {4: 0, 3: 2, 2: 4, 1: 6}          # index in TERMS of the student's first term
LAST_GRADED = 5                                 # T6 is the last completed term
# Honors rules as configured in js/data.js (HONORS_RULES) - used here only to CHECK the intended outcome.
BANDS = [('Summa Cum Laude', 1.00, 1.20), ('Magna Cum Laude', 1.21, 1.44), ('Cum Laude', 1.45, 1.75)]
MAINTAIN = {'Summa Cum Laude': 1.50, 'Magna Cum Laude': 1.75, 'Cum Laude': 2.00}

def snap(x, lo=1.00, hi=3.00):
    x = min(hi, max(lo, x))
    return min(GRID, key=lambda g: (abs(g - x), g))

def gwa(recs):
    u = sum(r['units'] for r in recs)
    return round(sum(r['units'] * r['grade'] for r in recs) / u, 2) if u else None

def band(g):
    for n, lo, hi in BANDS:
        if lo - 1e-9 <= g <= hi + 1e-9:
            return n
    return None

def slope(points):   # points: [(pos, gwa)]
    n = len(points); mx = sum(p for p, _ in points) / n; my = sum(g for _, g in points) / n
    sxx = sum((p - mx) ** 2 for p, _ in points); sxy = sum((p - mx) * (g - my) for p, g in points)
    return sxy / sxx

# ------------------------------------------------------------------------------------------------------------
# Roster: 14 fictional students per program. profile -> (term means for the student's graded terms, options).
# 'expect' documents the intended outcome; it is verified below and again with the application's own JavaScript.
# ------------------------------------------------------------------------------------------------------------
P = {
 'top':        dict(year=4, means=[1.25, 1.20, 1.20, 1.15, 1.20, 1.15], spread=0.14, maxg=1.50, expect=dict(band='Summa Cum Laude', eligible=True, trend='Stable')),
 'magna':      dict(year=4, means=[1.40, 1.35, 1.30, 1.30, 1.25, 1.30], spread=0.15, maxg=1.75, expect=dict(band='Magna Cum Laude', eligible=True)),
 'cumlaude':   dict(year=4, means=[1.75, 1.65, 1.60, 1.55, 1.55, 1.50], spread=0.18, maxg=2.00, expect=dict(band='Cum Laude', eligible=True)),
 'gradelimit': dict(year=4, means=[1.55, 1.55, 1.50, 1.55, 1.55, 1.50], spread=0.12, maxg=1.75, low=2.50, expect=dict(band='Cum Laude', eligible=False)),
 'average4':   dict(year=4, means=[2.10, 2.15, 2.05, 2.10, 2.20, 2.10], spread=0.25, maxg=3.00, expect=dict(band=None, trend='Stable')),
 'declining4': dict(year=4, means=[1.60, 1.75, 1.95, 2.15, 2.40, 2.60], spread=0.15, maxg=3.00, expect=dict(trend='Declining', flagged=True)),
 'gap4':       dict(year=4, means=[1.45, 1.40, 1.45, None, 1.40, 1.35], spread=0.12, maxg=1.75, expect=dict(gap=True)),
 'improving3': dict(year=3, means=[2.60, 2.30, 2.00, 1.75], spread=0.15, maxg=3.00, expect=dict(trend='Improving')),
 'high3':      dict(year=3, means=[1.30, 1.25, 1.25, 1.20], spread=0.12, maxg=1.75, expect=dict(trend='Stable')),
 'probation3': dict(year=3, means=[1.90, 2.10, 2.30, 2.50], spread=0.20, maxg=3.00, fails=2, expect=dict(trend='Declining', flagged=True, probation=True)),
 'inactive3':  dict(year=3, means=[2.20, 2.40, None, None], spread=0.20, maxg=3.00, status='Inactive', expect=dict(insufficient=True)),
 'second_hi':  dict(year=2, means=[1.35, 1.30], spread=0.15, maxg=1.75, expect=dict(insufficient=True)),
 'second_av':  dict(year=2, means=[2.00, 2.10], spread=0.25, maxg=3.00, expect=dict(insufficient=True)),
 'first':      dict(year=1, means=[], spread=0, maxg=3.00, expect=dict(no_records=True)),
}
ORDER = ['top', 'magna', 'cumlaude', 'gradelimit', 'average4', 'declining4', 'gap4',
         'improving3', 'high3', 'probation3', 'inactive3', 'second_hi', 'second_av', 'first']
# Per-program offset so the three programs do not look identical (BACOM slightly stronger, BSBA slightly weaker).
PROG_SHIFT = {'BACOM': -0.05, 'BSIT': 0.0, 'BSBA': 0.05}
PROG_CODE = {'BACOM': '11', 'BSIT': '12', 'BSBA': '13'}

# Fictional names (common Filipino given names and surnames; combinations are invented).
FIRST_F = ['Angela', 'Bianca', 'Camille', 'Danica', 'Erica', 'Frances', 'Gianna', 'Hazel', 'Isabel', 'Janelle', 'Kristine',
           'Lorraine', 'Mikaela', 'Nicole', 'Patricia', 'Rhea', 'Samantha', 'Trisha', 'Vanessa', 'Ysabel', 'Alyssa', 'Jasmine']
FIRST_M = ['Adrian', 'Bryan', 'Carlo', 'Dominic', 'Elijah', 'Francis', 'Gabriel', 'Harold', 'Ivan', 'Joshua', 'Kenneth',
           'Lorenzo', 'Marco', 'Nathan', 'Oliver', 'Paolo', 'Rafael', 'Simon', 'Tristan', 'Vincent', 'Miguel', 'Jerome']
LAST = ['Abad', 'Bautista', 'Castillo', 'Dizon', 'Espiritu', 'Fernandez', 'Galang', 'Hernandez', 'Ilagan', 'Jimenez',
        'Lacson', 'Manalo', 'Navarro', 'Ocampo', 'Pascual', 'Quiambao', 'Robles', 'Salazar', 'Tolentino', 'Umali',
        'Valdez', 'Yap', 'Zamora', 'Aguilar', 'Buenaventura', 'Cabrera', 'De Guzman', 'Evangelista', 'Flores', 'Gatchalian',
        'Lim', 'Mercado', 'Natividad', 'Perez', 'Ramirez', 'Santiago', 'Torres', 'Villareal', 'Agustin', 'Bernardo',
        'Corpuz', 'Domingo', 'Estrada', 'Francisco', 'Gomez']
MIDDLE = list('ABCDEFGHJLMNPRSTV')

def make_records(rng, prog, prof, shift):
    """Records for every graded term of this profile. Returns list of dicts (tid, code, title, units, grade, remarks)."""
    first = FIRST_TERM[prof['year']]; recs = []
    for k, mean in enumerate(prof['means']):
        ti = first + k
        if ti > LAST_GRADED or mean is None:
            continue
        cur_key = CUR_ORDER[k]
        subjects = [s for s in CUR[prog]['terms'][cur_key]['subjects'] if s[2] > 0]   # NSTP (0 units) not stored
        m = mean + shift
        rows = []
        for code, title, units in subjects:
            g = m + rng.gauss(0, prof['spread']) + (-0.25 if code.startswith('PFIT') else 0)
            rows.append(dict(tid=TERMS[ti][0], code=code, title=title, units=units, grade=snap(g, hi=prof['maxg'])))
        if prof.get('fails') and ti == LAST_GRADED:      # failures only in the latest term (probation case)
            cands = [r for r in rows if not r['code'].startswith(('PFIT', 'GENED'))] or rows
            for r in rng.sample(cands, prof['fails']):
                r['grade'] = 5.00
        recs += rows
    if prof.get('low'):                                   # one grade above the honors maintenance limit
        cands = [r for r in recs if not r['code'].startswith('PFIT')]
        rng.choice(cands)['grade'] = prof['low']
    for r in recs:
        r['remarks'] = 'Passed' if r['grade'] <= 3.00 else 'Failed'
    return recs

def check(prof, recs):
    """True when the generated records produce the intended outcome (same rules as the application)."""
    e = prof['expect']
    if e.get('no_records'):
        return not recs
    terms = sorted({r['tid'] for r in recs}, key=lambda t: [x[0] for x in TERMS].index(t))
    pts = [([x[0] for x in TERMS].index(t), gwa([r for r in recs if r['tid'] == t])) for t in terms]
    cum = gwa(recs); b = band(cum); low = max(r['grade'] for r in recs)
    if e.get('insufficient'):
        return len(pts) < 3
    if 'band' in e and b != e['band']:
        return False
    if 'eligible' in e:
        ok = b is not None and low <= MAINTAIN[b] + 1e-9
        if ok != e['eligible']:
            return False
    if 'trend' in e:
        s = round(slope(pts), 4); t = 'Improving' if s < -0.03 else 'Declining' if s > 0.03 else 'Stable'
        if t != e['trend']:
            return False
    if e.get('flagged'):
        last = [r for r in recs if r['tid'] == terms[-1]]
        if not (pts[-1][1] > 2.00 or any(r['grade'] >= 5 for r in last) or e.get('trend') == 'Declining'):
            return False
    if e.get('probation'):
        last = [r for r in recs if r['tid'] == terms[-1]]
        if sum(r['grade'] >= 5 for r in last) < 2:
            return False
    if e.get('gap'):
        if 'T4' in terms or band(cum) is None:
            return False
    return True

def main():
    used_names, used_last, students, records, rid = set(), set(), [], [], 1
    sid = 5001
    for prog in ['BACOM', 'BSIT', 'BSBA']:
        for n, key in enumerate(ORDER):
            prof = P[key]
            seed = int(hashlib.sha256(f'{prog}-{key}'.encode()).hexdigest()[:8], 16)
            rng = random.Random(seed)
            for attempt in range(500):                         # deterministic retries until the outcome matches
                recs = make_records(random.Random(seed + attempt), prog, prof, PROG_SHIFT[prog])
                if check(prof, recs):
                    break
            else:
                sys.exit(f'Could not generate {prog}/{key}')
            # fictional name, unique across the data set
            while True:
                female = rng.random() < 0.5
                first = rng.choice(FIRST_F if female else FIRST_M); last = rng.choice(LAST)
                if last not in used_last and sum(f == first for f, _ in used_names) < 2:
                    used_names.add((first, last)); used_last.add(last); break
            entry = {4: '23', 3: '24', 2: '25', 1: '26'}[prof['year']]
            num = f"DEMO-{entry}-{PROG_CODE[prog]}{n + 1:02d}"
            probation = prof['expect'].get('probation', False)
            students.append(dict(id=sid, num=num, last=last, first=first, middle=rng.choice(MIDDLE) + '.', prog=prog,
                                 yr=prof['year'], sec='A' if n % 2 == 0 else 'B',
                                 standing='Probation' if probation else 'Good Standing',
                                 status=prof.get('status', 'Active'), profile=key, expect=prof['expect']))
            for r in recs:
                records.append(dict(rid=rid, sid=sid, **r)); rid += 1
            sid += 1
    out = dict(terms=TERMS, students=students, records=records)
    json.dump(out, open(os.path.join(HERE, 'demo.json'), 'w'), indent=1)
    by = {}
    for s in students:
        by.setdefault(s['prog'], 0); by[s['prog']] += 1
    print(f"{len(students)} students {by}, {len(records)} subject records")

if __name__ == '__main__':
    main()
