// =====================================================================================
// MODULE 1 DATA - from the AEI database (MySQL), embedded in the page by PHP (includes/layout.php, m1_page_data()).
//   Admin pages:   all students and all subject records.
//   Student pages: ONLY the signed-in student's own profile and subject records (chosen by the server session).
// Module 1 is no longer stored in localStorage. Changes are sent to api/students.php and api/records.php,
// which validate them on the server and answer with the refreshed data (m1Apply).
// =====================================================================================
const M1=(window.AEI_BOOT&&window.AEI_BOOT.data)||{terms:[],students:[],records:[]};
const STORE_KEY='aei_students';   // Phase 1-2 browser copy of the students - removed below, no longer used
try{localStorage.removeItem(STORE_KEY);localStorage.removeItem('aei_records')}catch(e){}
// Student fields used by the screens: id, num (student number), name ("Last, First M."), last, first, middle,
// prog, yr, sec, st (standing, set manually), status, hasAccount; gwa/prev are filled from the records (syncGWA).
const toStudent=x=>({...x,gwa:null,prev:M1.terms.map(()=>null)});   // one GWA slot per academic term
const S=M1.students.map(toStudent);
// ---- Module 1 academic records (database tables academic_terms and subject_records) ----
//   R (subject records): {rid,sid,tid,code,title,units,grade,remarks,test}
//        test = true: marked as test data in the database (subject_records.is_test). Shown, labelled, never counted.
//        rid = record ID in the database, sid = student id (S[].id), tid = term code. One student -> many terms -> many records.
// ALL_TERMS: every academic term in the database, chronological (sort_order). Module 1 screens use it
//   (dropdowns, Add Subject, Edit Grades, CSV import, history). name = "AY 2024–2025, 1st Semester",
//   short = "1st Sem 2024–25" (chart axes), legacy = stored term_name (also accepted in the CSV term column).
// TERMS: the terms that have at least one COUNTED grade for any student (server flag "loaded"). Used only as the
//   axis of the semester series and charts (Modules 3 and 5), so empty terms are not plotted. It does NOT decide
//   whether a student has a term gap: honors checks gaps between that student's own first and latest term.
const termLabel=t=>t.ay&&t.sem?`AY ${t.ay.replace('-','–')}, ${t.sem}`:t.name;
const termShort=t=>/^\d{4}-\d{4}$/.test(t.ay||'')&&t.sem?`${t.sem.replace(/ Semester$/,' Sem')} ${t.ay.slice(0,4)}–${t.ay.slice(7,9)}`:t.name;
const ALL_TERMS=M1.terms.map(t=>({id:t.id,name:termLabel(t),short:termShort(t),legacy:t.name,ay:t.ay||'',sem:t.sem||'',loaded:!!t.loaded}));
const TERMS=[],SEM=[];   // filled by refreshTerms(); arrays are kept (not reassigned) so every screen sees updates
function refreshTerms(){const L=ALL_TERMS.filter(t=>t.loaded);TERMS.length=0;TERMS.push(...(L.length?L:ALL_TERMS));SEM.length=0;SEM.push(...TERMS.map(t=>t.short))}
refreshTerms();
// Find a term from CSV "academic_year" + "semester" values (same matching as m1_term_by_year_and_semester() in PHP).
const semKey=s=>String(s||'').trim().toLowerCase().replace(/\s*(semester|sem\.?)\s*$/,'').trim().replace(/^first$/,'1st').replace(/^second$/,'2nd');
const termByYearSem=(y,s)=>{y=String(y||'').trim().replace(/\s*[-\u2013\u2014]\s*/g,'-');return y&&semKey(s)?ALL_TERMS.find(t=>t.ay===y&&semKey(t.sem)===semKey(s)):undefined};
const REMARKS=['Passed','Failed'];
const REC_KEY='aei_records';   // Phase 1-2 key, no longer used
let R=M1.records.map(r=>({...r}));
const recOf=(sid,tid)=>R.filter(r=>r.sid==sid&&r.tid==tid);   // ALL rows of a student and term (display, duplicate checks)
const counted=r=>!r.test;   // test records are never counted in GWA, honors or analytics
const countedOf=(sid,tid)=>recOf(sid,tid).filter(counted);
// Latest term in which this student has counted records (null if none). Used for the student's own "latest term".
const latestTermOf=sid=>{for(let i=ALL_TERMS.length-1;i>=0;i--)if(countedOf(sid,ALL_TERMS[i].id).length)return ALL_TERMS[i];return null};
// PROVISIONAL semester GWA = sum(units x grade) / sum(units), rounded to 2 decimals (the weighted-average procedure
// described in the Student Handbook). Rounding/decimal rules and exclusions (e.g. NSTP) are NOT confirmed by PLMun.
// Replace ONLY this function when the official formula is confirmed.
function calcGWA(rs){const u=rs.reduce((t,r)=>t+r.units,0);return u?Math.round(rs.reduce((t,r)=>t+r.units*r.grade,0)/u*100)/100:null}
// Keep the Student Profile GWA in step with the records (latest term = current GWA). Display only: GWA is never stored.
function syncGWA(sid,tid){const s=S.find(x=>x.id==sid),k=ALL_TERMS.findIndex(t=>t.id==tid),g=calcGWA(countedOf(sid,tid));if(!s||k<0)return;s.prev[k]=g;s.gwa=null;
 for(let i=s.prev.length-1;i>=0;i--)if(s.prev[i]!=null){s.gwa=s.prev[i];break}}   // s.gwa = latest term with grades
// Validation shared by Add Subject, Edit Grades and Import. Returns {field:message}; empty object = valid.
function validateSubject(o){const e={},code=String(o.code||'').trim(),title=String(o.title||'').trim();
 if(!/^[A-Za-z0-9][A-Za-z0-9 \-]{1,11}$/.test(code))e.code='Subject code is required (2-12 letters/numbers).';
 if(title.length<3||title.length>80||/[<>]/.test(title))e.title='Subject description is required (3-80 characters, no < >).';
 if(!/^\d+$/.test(String(o.units).trim())||+o.units<1||+o.units>12)e.units='Units must be a whole number from 1 to 12.';
 if(!/^\d(\.\d{1,2})?$/.test(String(o.grade).trim())||+o.grade<1||+o.grade>5)e.grade='Grade must be a number from 1.00 to 5.00.';
 if(!REMARKS.includes(o.remarks))e.remarks='Choose Passed or Failed.';
 else if(!e.grade&&+o.grade<=3&&o.remarks=='Failed')e.remarks='A grade of 3.00 or better cannot be marked Failed (Handbook grading scale).';
 else if(!e.grade&&+o.grade>=5&&o.remarks=='Passed')e.remarks='A grade of 5.00 cannot be marked Passed.';
 return e}
const autoRemark=g=>+g<=3?'Passed':'Failed';   // convenience default only; the Admin can change it
// Minimal CSV parser (handles quoted fields and a BOM)
function parseCSV(t){t=t.replace(/^\uFEFF/,'');const rows=[];let row=[],f='',q=false;
 for(let i=0;i<t.length;i++){const c=t[i];
  if(q){if(c=='"'){if(t[i+1]=='"'){f+='"';i++}else q=false}else f+=c}
  else if(c=='"')q=true;else if(c==','){row.push(f);f=''}
  else if(c=='\n'||c=='\r'){if(c=='\r'&&t[i+1]=='\n')i++;row.push(f);f='';if(row.some(x=>x.trim()))rows.push(row);row=[]}else f+=c}
 row.push(f);if(row.some(x=>x.trim()))rows.push(row);return rows}
S.forEach(s=>ALL_TERMS.forEach(t=>syncGWA(s.id,t.id)));
// Replace the Module 1 data with the refreshed copy the server sent back after a change (same object S, new contents).
function m1Apply(d){if(!d)return;S.length=0;(d.students||[]).forEach(x=>S.push(toStudent(x)));R=(d.records||[]).map(r=>({...r}));
 R.filter(counted).forEach(r=>{const t=ALL_TERMS.find(x=>x.id==r.tid);if(t)t.loaded=true});refreshTerms();   // a term that just received its first counted grade joins the chart axis
 S.forEach(s=>ALL_TERMS.forEach(t=>syncGWA(s.id,t.id)))}

// Re-read the data that is still kept in the browser (Module 4 activities, activity log) when another tab changed it.
// Module 1 data comes from the server; reload the page to see changes made elsewhere.
function reloadData(){if(typeof loadCo=='function')loadCo(true);if(typeof loadLog=='function')loadLog()}

// =====================================================================================
// MODULE 2 - HONORS RULES.  PROVISIONAL: taken from the PLMun Student Handbook (Sec. XII)
// and NOT yet confirmed by PLMun. Edit ONLY this block / the four small functions below
// when the official rules are confirmed.
// =====================================================================================
const HONORS_RULES={
 excludePattern:/nstp|national service training/i,   // Handbook: NSTP grades are not counted in the honors GWA (NSTP must be Passed - not verified here)
 screenYear:4,   // assumption: honors are screened for graduating (4th-year) students
 // Step 1 - GWA bands [name, min, max] (inclusive, 2 decimals). GWA basis = cumulative units-weighted GWA over ALL
 // valid subject records of the student (handbook does not say; confirm cumulative vs. other basis).
 bands:[['Summa Cum Laude',1.00,1.20],['Magna Cum Laude',1.21,1.44],['Cum Laude',1.45,1.75]],
 // Step 2 - "must maintain grades not lower than ...". PROVISIONAL reading: no recorded subject grade may be worse
 // (numerically higher) than the limit. The handbook wording is ambiguous (could mean per semester / major subjects).
 maintain:{'Summa Cum Laude':1.50,'Magna Cum Laude':1.75,'Cum Laude':2.00},
 // Step 3 - requirements the prototype has NO data for. They are never treated as passed.
 pending:['Complete 4-Year History','Residency','Academic Load','No Major Violation','Other Requirements'],   // short checklist names for everything the prototype cannot verify (always Pending Verification)
 manual:['No major violation from 1st to 4th year (needs OSA / Prefect records)','Four-year residency and full academic load (needs registrar data)','Complete 1st-4th year Certificate of Grades (only the semesters that have grades in Academic Records are checked here, not a full 4-year history)','NSTP subjects must be Passed (NSTP grades are excluded from the honors GWA)','No incomplete grades / repeated subjects (not tracked in the system)','Dean\'s recommendation','University Council final deliberation and Board of Regents approval']};
// Short status labels. "Eligible (preliminary)" never means an official decision: other requirements stay Pending Verification.
const STATUS={eligible:['Eligible (preliminary)','g'],notelig:['Not Eligible','r'],review:['Needs Review','y']};
const CHK={passed:['Passed','g'],notmet:['Not Met','r'],pending:['Pending Verification','y']};   // checklist labels
const termName=id=>(ALL_TERMS.find(t=>t.id==id)||{}).name||String(id);
// Step 1: GWA category (null = outside every range)
function honorsBand(gwa){if(gwa==null)return null;const b=HONORS_RULES.bands.find(x=>gwa>=x[1]-1e-9&&gwa<=x[2]+1e-9);return b?b[0]:null}
// Step 2: lowest-grade requirement for a category -> {limit, ok}
function checkLowestGrade(cat,low){const limit=HONORS_RULES.maintain[cat];return{limit,ok:low<=limit+1e-9}}
// Steps 3 + 4: run everything for one student and explain the result. Reads Module 1 data (S and R) only.
function evaluateHonors(s){const C=HONORS_RULES,recs=R.filter(r=>r.sid==s.id&&counted(r)),f=n=>Number(n).toFixed(2);   // test records are left out
 const valid=r=>Number.isFinite(+r.grade)&&+r.grade>=1&&+r.grade<=5&&Number.isFinite(+r.units)&&+r.units>0&&ALL_TERMS.some(t=>t.id==r.tid);
 const all=recs.filter(valid).map(r=>({...r,grade:+r.grade,units:+r.units})),bad=recs.filter(r=>!valid(r));
 const excluded=all.filter(r=>C.excludePattern.test(r.code+' '+r.title)),good=all.filter(r=>!excluded.includes(r));   // NSTP is left out of the honors GWA
 // Term gap = a term with no counted records BETWEEN this student's own first and latest term with records.
 // A gap is NOT proof of missing grades (it may be a leave of absence, a shifted schedule, or grades not yet entered):
 // it only asks for verification. Terms before the first / after the latest term are not judged (the system holds
 // no enrolment or residency data); the full 4-year history stays a manual check (Certificate of Grades).
 // (Kept under the name "missing" for compatibility; "gaps" is the same list.)
 const own=ALL_TERMS.map((t,k)=>all.some(r=>r.tid==t.id)?k:-1).filter(k=>k>=0);
 const missing=own.length?ALL_TERMS.slice(own[0],own[own.length-1]+1).filter(t=>!all.some(r=>r.tid==t.id)).map(t=>t.name):[];
 const gwa=calcGWA(good),lowRec=good.reduce((m,r)=>!m||r.grade>m.grade?r:m,null),low=lowRec?lowRec.grade:null;
 const o={s,gwa,low,lowRec,good,bad,missing,gaps:missing,excluded,category:null,key:'review',reasons:[],unverified:C.manual.slice(),checks:[],summary:'',note:''},add=(t,m)=>o.reasons.push({t,m});
 const ck=(name,status)=>({name,status}),pend=C.pending.map(n=>ck(n,'pending'));
 // --- scope: honors are screened for graduating students only (assumption)
 if(C.screenYear&&s.yr!=C.screenYear){o.key='notelig';o.summary=`Not a ${C.screenYear}th-year student.`;add('info',`Outside the screening scope: honors are screened for graduating (${C.screenYear}th-year) students (assumption to confirm).`);o.checks=[ck('Graduating (4th-year) Student','notmet')];return o}
 if(!good.length){o.summary='No academic records.';add('warn','No valid academic records in Module 1'+(bad.length?` (${bad.length} invalid record(s) ignored)`:'')+'.');o.checks=[ck('GWA Requirement','pending'),ck('Grade Requirement','pending'),ck('No Unverified Term Gaps','pending'),...pend];return o}
 // --- step 1: GWA category, step 2: lowest-grade requirement
 o.category=honorsBand(gwa);
 if(excluded.length)add('info',`${excluded.length} NSTP subject(s) are not counted in the honors GWA (Handbook). NSTP must be Passed: not verified.`);
 add('info',`GWA covers the ${all.length>0?new Set(all.map(r=>r.tid)).size:0} recorded semester(s); the complete 4-year history is verified manually.`);
 add(o.category?'ok':'fail',o.category?`GWA ${f(gwa)} (cumulative, ${good.length} subject records) is within the ${o.category} range.`:`GWA ${f(gwa)} (cumulative) is outside every Latin-honors range (1.00-1.75).`);
 const chk=o.category?checkLowestGrade(o.category,low):null,lenient=Math.max(...Object.values(C.maintain));let fail=false;
 const where=`${lowRec.code}, ${termName(lowRec.tid)}`;
 if(chk){add(chk.ok?'ok':'fail',`Lowest recorded grade ${f(low)} (${where}) ${chk.ok?'meets':'is worse than'} the ${f(chk.limit)} grade-maintenance limit for ${o.category} (provisional reading).`);fail=!chk.ok}
 else if(low>lenient+1e-9){add('fail',`Lowest recorded grade ${f(low)} (${where}) is worse than 2.00, the most lenient maintenance limit.`);fail=true}
 const incomplete=missing.length>0||bad.length>0;
 if(missing.length)add('warn','Term gap detected — verify enrollment and academic records: '+missing.join('; ')+'. (No counted subject records in '+(missing.length==1?'this term, which lies':'these terms, which lie')+' between the student\'s first and latest term with records. The system does not decide whether grades are missing or the student was not enrolled.)');
 if(bad.length)add('warn',`${bad.length} invalid subject record(s) were ignored (missing/invalid grade, units or term).`);
 if(!o.category&&!fail&&!incomplete)fail=true;   // complete data and GWA above 1.75
 // --- step 3: checklist. Anything that cannot be checked is Pending Verification, never Passed.
 const gradeBad=chk?!chk.ok:low>lenient+1e-9;
 o.checks=[ck('GWA Requirement',incomplete?'pending':o.category?'passed':'notmet'),
  ck('Grade Requirement',gradeBad?'notmet':(chk&&!incomplete)?'passed':'pending'),
  ck('No Unverified Term Gaps',incomplete?'pending':'passed'),...pend];
 // --- step 4: status + SHORT automatic reason (details stay in o.reasons for the details window)
 o.key=fail?'notelig':incomplete?'review':'eligible';
 if(o.key=='review')add('warn','Result is provisional until the term gap or invalid records are verified.');
 o.summary=o.key=='eligible'?'Meets GWA and grade requirements.':o.key=='notelig'?(o.checks[0].status=='notmet'?'GWA does not meet requirements.':'Grade requirement not met.'):(missing.length?'Term gap detected — verify records.':'Invalid grade records.');
 if(o.key=='eligible')o.note='Based on the recorded terms; other requirements pending verification.';
 return o}

// =====================================================================================
// MODULE 3 - PREDICTIVE ANALYTICS & TREND FORECASTING.  RULE-BASED / BASIC STATISTICS ONLY.
// No AI or machine learning. All thresholds are PROVISIONAL and must be reviewed with PLMun.
// Assumption (confirm with PLMun): lower GWA = better performance (1.00 best ... 5.00 failing).
// =====================================================================================
const ANALYTICS={
 minTerms:3,      // need at least this many semesters WITH subject records, otherwise "Insufficient Data"
 flat:0.03,       // trend threshold: slope (GWA change per semester) within +/-0.03 = Stable
 watchGwa:2.00,   // provisional "watch" level: latest GWA worse than this is flagged for support
 failGrade:5.00,  // a recorded grade at/above this in the latest semester is flagged
 scaleMin:1.00,scaleMax:5.00};   // forecasts are clamped to the grading scale
const A_ORDER={Improving:0,Stable:1,Declining:2};
const r2=n=>Math.round(n*100)/100,fx=n=>n==null?'—':Number(n).toFixed(2);
const validRec=r=>Number.isFinite(+r.grade)&&r.grade!==''&&r.grade!==null&&+r.grade>=1&&+r.grade<=5&&Number.isFinite(+r.units)&&+r.units>0&&ALL_TERMS.some(t=>t.id==r.tid)&&counted(r);
// Semester GWA series for ONE student, built from Module 1 subject records (recOf + calcGWA). gwa=null when no valid records.
// One entry per term in TERMS (terms with counted records in the system).
//   k   = index in TERMS     (only for cohort tables/charts that are laid out on TERMS)
//   pos = index in ALL_TERMS (the term's real chronological position: used for trend regression and forecasts,
//         so a semester with no data anywhere still counts as one semester of distance)
function semesterSeries(sid){return TERMS.map((t,k)=>{const a=recOf(sid,t.id).filter(validRec).map(r=>({...r,grade:+r.grade,units:+r.units}));return{k,pos:ALL_TERMS.findIndex(x=>x.id==t.id),tid:t.id,name:t.name,gwa:calcGWA(a),n:a.length,recs:a}})}
// Step 1 trend (least-squares slope of GWA against the CHRONOLOGICAL semester position p.pos), Step 2 forecast (same line,
// one semester after the student's latest term: ALL_TERMS[latest.pos+1]), Step 3 flags.
function analyzeStudent(s){const C=ANALYTICS,series=semesterSeries(s.id),pts=series.filter(x=>x.gwa!=null),latest=pts.length?pts[pts.length-1]:null,
 o={s,series,pts,terms:pts.length,latest,slope:null,trend:'Insufficient Data',forecast:null,rawForecast:null,clamped:false,direction:null,status:'insufficient',flags:[],support:[],reason:'',nextName:null};
 if(pts.length<C.minTerms){o.reason=`Only ${pts.length} ${pts.length==1?'semester has':'semesters have'} counted subject records (at least ${C.minTerms} are needed), so no trend or forecast is shown.`;o.support=['Add or import the missing semester records in Academic Records to enable the analysis.'];o.rs='Limited academic history.';o.ss='Collect more records.';return o}
 const n=pts.length,mx=pts.reduce((t,p)=>t+p.pos,0)/n,my=pts.reduce((t,p)=>t+p.gwa,0)/n,sxx=pts.reduce((t,p)=>t+(p.pos-mx)**2,0),sxy=pts.reduce((t,p)=>t+(p.pos-mx)*(p.gwa-my),0);
 o.slope=sxy/sxx;const icpt=my-o.slope*mx,nk=latest.pos+1;o.nextPos=nk;o.nextName=nk<ALL_TERMS.length?ALL_TERMS[nk].name:'Next semester';
 const sl=Math.round(o.slope*1e4)/1e4;   // round first so a slope of exactly +/-0.03 is not misclassified by floating-point noise
 o.trend=sl<-C.flat?'Improving':sl>C.flat?'Declining':'Stable';          // lower GWA = better, so a falling GWA is Improving
 o.rawForecast=icpt+o.slope*nk;o.forecast=r2(Math.min(C.scaleMax,Math.max(C.scaleMin,o.rawForecast)));o.clamped=Math.abs(o.forecast-r2(o.rawForecast))>1e-9;
 const d=r2(o.forecast-latest.gwa);o.direction=d<-C.flat?'improvement':d>C.flat?'a decline':'stable performance';
 if(o.trend=='Declining')o.flags.push(`GWA trend is declining (about ${o.slope>=0?'+':''}${o.slope.toFixed(2)} per semester).`);
 if(latest.gwa>C.watchGwa+1e-9)o.flags.push(`Latest semester GWA ${fx(latest.gwa)} is above the provisional watch level of ${fx(C.watchGwa)}.`);
 if(latest.recs.some(r=>r.grade>=C.failGrade-1e-9))o.flags.push(`A grade of ${fx(C.failGrade)} was recorded in the latest semester.`);
 o.status=o.flags.length?'needs':'ok';
 // SHORT automatic reason / support (same predefined conditions for every student; no manual typing)
 const hi=latest.gwa>C.watchGwa+1e-9,f5=latest.recs.some(r=>r.grade>=C.failGrade-1e-9),TX={Improving:['GWA is decreasing.','Continue monitoring.'],Stable:['GWA is consistent.','Maintain performance.'],Declining:['GWA is increasing.','Consider academic advising.']},pr=[];
 if(f5)pr.push('Failing grade recorded.');if(o.trend=='Declining')pr.push(TX.Declining[0]);if(hi)pr.push(`GWA above ${fx(C.watchGwa)}.`);if(!pr.length)pr.push(TX[o.trend][0]);
 o.rs=pr.join(' ');o.ss=f5?'Review recent subject grades.':o.trend=='Declining'?'Consider academic advising.':hi?'Consider tutoring or advising.':TX[o.trend][1];
 if(o.status=='ok'){o.reason='No flags under the provisional rules.';o.support=['No action needed; continue regular monitoring.']}
 else{o.reason=o.flags.join(' ');o.support=['Review recent subject grades.','Monitor academic performance next semester.'];
  if(o.flags.some(f=>/watch level|was recorded/.test(f)))o.support.push('Consider academic advising or tutoring.')}
 return o}

// Chart data for ONE student's trend + estimate (Analytics "one student" view and Student Insights).
// Axis = every term in ALL_TERMS from the first to the last term in TERMS (terms without data anywhere stay as empty
// slots, so the real spacing is visible), plus one more slot for the estimate when the next term is beyond that range.
// Chronological chart axis shared by the student charts: every ALL_TERMS term from the first to the last term in TERMS.
function chartAxis(){const tp=TERMS.map(t=>ALL_TERMS.findIndex(x=>x.id==t.id)),first=tp.length?tp[0]:0,last=tp.length?tp[tp.length-1]:ALL_TERMS.length-1;
 return{first,last,span:ALL_TERMS.slice(first,last+1)}}
// One student's semester GWAs on that axis (null = no counted records in that term), e.g. for the Student Dashboard.
function studentSeriesChart(sid){const {span}=chartAxis(),series=semesterSeries(sid);
 return{labels:span.map(t=>t.short),data:span.map(t=>{const x=series.find(e=>e.tid==t.id);return x?x.gwa:null})}}
function trendChartData(a){const {first,last,span}=chartAxis();
 const labels=span.map(t=>t.short),actual=span.map(t=>{const x=a.series.find(e=>e.tid==t.id);return x?x.gwa:null});
 const next=a.forecast!=null?a.nextPos:last+1;let ei=next-first;
 if(next>last){labels.push(next<ALL_TERMS.length?ALL_TERMS[next].short+' (estimate)':'Next semester (estimate)');actual.push(null);ei=labels.length-1}
 let estimate=null;if(a.forecast!=null){estimate=labels.map(()=>null);estimate[a.latest.pos-first]=a.latest.gwa;estimate[ei]=a.forecast}
 return{labels,actual,estimate}}

// The student's CURRENT GWA = GWA of the latest semester that has subject records (null if none). Computed, never typed in.
const curGWA=s=>{const p=semesterSeries(s.id).filter(x=>x.gwa!=null);return p.length?p[p.length-1].gwa:null};

// =====================================================================================
// MODULE 4 - HOLISTIC EVALUATION & CO-CURRICULAR TRACKING.  Counting only: NO official scoring.
// One activity = one row in ACTS. The student is referenced by id (sid); name/program/year are
// always read from S (Module 1), never copied. Verification statuses control what is counted.
// =====================================================================================
// Each database has its own browser storage for Module 4 (dataSet is '' for the default database, so existing data is kept).
const DS=(window.AEI_BOOT&&window.AEI_BOOT.dataSet)?'_'+window.AEI_BOOT.dataSet:'';
const ACT_KEY='aei_activities'+DS,CAT_KEY='aei_act_categories'+DS;
const DEFAULT_CATS=['Leadership','Organization Membership','Community Involvement','Academic Competition','Technical Competition','Seminar and Training','Volunteer Activities','Other'];
const LEVELS=['College','University','Barangay','City','Regional','National','International'];   // provisional list taken from the handbook award tables - confirm with PLMun/OSA
const VERIFY={'Pending Verification':'y','Verified':'g','Rejected':'r'};                          // status -> badge colour
// Summary groups used by the cards/summary (edit here). Categories not listed (e.g. Other, custom ones) count only in the totals.
const CO_GROUPS={Leadership:['Leadership'],Organization:['Organization Membership'],Community:['Community Involvement','Volunteer Activities'],Competitions:['Academic Competition','Technical Competition'],Training:['Seminar and Training']};
let CATS=DEFAULT_CATS.slice(),ACTS=[];
// Student accounts: keep ONLY the student's own activities in memory and never write browser storage (the Admin's
// copy in a shared browser is not overwritten, and other students' activities are never held by a Student page).
const CO_SELF=(window.AEI_BOOT&&window.AEI_BOOT.user&&window.AEI_BOOT.user.role==='student')?Number((window.AEI_BOOT.user.student||{}).studentId):null;
const saveActs=()=>{if(CO_SELF===null)localStorage.setItem(ACT_KEY,JSON.stringify(ACTS))},saveCats=()=>{if(CO_SELF===null)localStorage.setItem(CAT_KEY,JSON.stringify(CATS))};
const studentName=sid=>(S.find(s=>s.id==sid)||{name:'Unknown student'}).name;
const actsOf=sid=>ACTS.filter(a=>a.sid==sid);
const nextAid=()=>'A'+String(Math.max(0,...ACTS.map(a=>+String(a.aid).slice(1)||0))+1).padStart(3,'0');
// [sid, title, category, organization/event, role, level, academic year, recognition, verification, description]  (FICTIONAL sample)
// Module 4 sample activities: sent by the server with the page (Admin: all samples; Student: only their own).
// [sid,title,category,organization,role,level,year,recognition,verification,notes,activityId]
const SEED_ACTS=(window.AEI_BOOT&&window.AEI_BOOT.seedActs)||[];
// Validation shared by Add / Edit. Returns {field: message}. selfAid = the record being edited (ignored in the duplicate check).
function validateActivity(o,selfAid){const e={},t=String(o.title||'').trim(),has=/[<>]/;
 if(!S.some(s=>s.id==o.sid))e.sid='Choose an existing student (Student ID not found).';
 if(t.length<3||t.length>80||has.test(t))e.title='Activity title is required (3-80 characters, no < >).';
 if(!CATS.includes(o.cat))e.cat='Choose a category.';
 if(String(o.org||'').length>60||has.test(o.org||''))e.org='Organization/event: up to 60 characters, no < >.';
 if(String(o.role||'').length>40||has.test(o.role||''))e.role='Position/role: up to 40 characters, no < >.';
 if(!LEVELS.includes(o.level))e.level='Choose a participation level.';
 const y=String(o.year||'').match(/^(\d{4})-(\d{4})$/);if(!y||+y[2]!=+y[1]+1)e.year='Academic year must look like 2024-2025.';
 if(String(o.desc||'').length>200||has.test(o.desc||''))e.desc='Description: up to 200 characters, no < >.';
 if(String(o.achv||'').length>60||has.test(o.achv||''))e.achv='Achievement: up to 60 characters, no < >.';
 if(!VERIFY.hasOwnProperty(o.ver))e.ver='Choose a verification status.';
 if(!e.sid&&!e.title&&!e.year&&ACTS.some(a=>a.aid!==selfAid&&a.sid==o.sid&&a.cat===o.cat&&a.year===o.year&&a.title.trim().toLowerCase()===t.toLowerCase()))e.title='Duplicate: this student already has this activity (same title, category and year).';
 return e}
// Verified-only summary for ONE student (also the data source Module 5 can reuse).
function coSummary(sid){const all=actsOf(sid),ver=all.filter(a=>a.ver=='Verified'),groups={};
 Object.entries(CO_GROUPS).forEach(([g,cats])=>groups[g]=ver.filter(a=>cats.includes(a.cat)).length);
 const hi=ver.map(a=>LEVELS.indexOf(a.level)).filter(i=>i>=0);
 return{sid,total:all.length,verified:ver.length,pending:all.filter(a=>a.ver=='Pending Verification').length,rejected:all.filter(a=>a.ver=='Rejected').length,groups,byCategory:Object.fromEntries(CATS.map(c=>[c,ver.filter(a=>a.cat==c).length])),highest:hi.length?LEVELS[Math.max(...hi)]:null}}
const coSummaryAll=()=>S.map(s=>coSummary(s.id));
function loadCo(reloadOnly){let v=null;try{v=JSON.parse(localStorage.getItem(ACT_KEY))}catch(e){}
 if(Array.isArray(v))ACTS=CO_SELF===null?v:v.filter(a=>Number(a.sid)===CO_SELF);else if(!reloadOnly){ACTS=SEED_ACTS.filter(r=>S.some(s=>s.id==r[0])).map((r,k)=>({aid:r[10]||'A'+String(k+1).padStart(3,'0'),sid:r[0],title:r[1],cat:r[2],org:r[3],role:r[4],level:r[5],year:r[6],achv:r[7],ver:r[8],desc:r[9]}));saveActs()}
 let c=null;try{c=JSON.parse(localStorage.getItem(CAT_KEY))}catch(e){}if(Array.isArray(c)&&c.length&&c.every(x=>typeof x=='string'))CATS=c;else if(!reloadOnly){CATS=DEFAULT_CATS.slice();saveCats()}}
loadCo();

// =====================================================================================
// MODULE 5 - EXECUTIVE INSIGHTS DASHBOARD.  No new rules: it only COUNTS and COMBINES the results of
// Modules 1-4 (calcGWA, evaluateHonors, analyzeStudent, coSummary). Insights are fixed if-statements, not AI.
// =====================================================================================
// ---- Real activity log (recent activity + notifications). Newest first, last 50 events kept. ----
const LOG_KEY='aei_log'+DS;let LOG=[];
function loadLog(){try{const v=JSON.parse(localStorage.getItem(LOG_KEY));LOG=Array.isArray(v)?v:[]}catch(e){LOG=[]}}
function logEvent(text,page){LOG.unshift({t:new Date().toISOString(),text,page:page||''});LOG=LOG.slice(0,50);localStorage.setItem(LOG_KEY,JSON.stringify(LOG))}
loadLog();
// ---- Statistics for a list of students (default: everyone). opts: {prog, yr} filters. ----
function executiveStats(list){list=list||S;const n=list.length,count=(arr,f)=>arr.reduce((m,x)=>{const k=f(x);m[k]=(m[k]||0)+1;return m},{});
 const an=list.map(analyzeStudent),gw=list.map(curGWA).filter(x=>x!=null),sum=a=>a.reduce((t,x)=>t+x,0);
 const grad=list.filter(s=>s.yr==HONORS_RULES.screenYear),ev=grad.map(evaluateHonors),ids=new Set(list.map(s=>s.id)),acts=ACTS.filter(a=>ids.has(a.sid)),co=list.map(s=>coSummary(s.id));
 const trend=t=>an.filter(a=>a.trend==t).length,cat=c=>ev.filter(e=>e.key=='eligible'&&e.category==c).length;
 const progs=[...new Set(list.map(s=>s.prog))].sort(),rows=progs.map(p=>{const m=list.filter(s=>s.prog==p),g=m.map(curGWA).filter(x=>x!=null),mids=new Set(m.map(s=>s.id)),h=m.filter(s=>s.yr==HONORS_RULES.screenYear).map(evaluateHonors);
  return{prog:p,students:m.length,avgGwa:g.length?sum(g)/g.length:null,honors:h.filter(e=>e.key=='eligible').length,support:m.map(analyzeStudent).filter(a=>a.status=='needs').length,verified:ACTS.filter(a=>mids.has(a.sid)&&a.ver=='Verified').length}});
 return{n,active:list.filter(s=>s.status=='Active').length,
  autoProbation:list.filter(s=>probationCheck(s.id).met).length,byProgram:count(list,s=>s.prog),byYear:count(list,s=>s.yr),byStanding:count(list,s=>s.st),
  avgGwa:gw.length?sum(gw)/gw.length:null,noRecords:list.filter((s,i)=>an[i].terms==0).length,
  termAvg:TERMS.map((t,k)=>{const g=list.map(s=>semesterSeries(s.id)[k].gwa).filter(x=>x!=null);return{name:t.name,short:t.short,n:g.length,avg:g.length?sum(g)/g.length:null}}),
  honors:{scope:grad.length,eligible:ev.filter(e=>e.key=='eligible').length,review:ev.filter(e=>e.key=='review').length,notelig:ev.filter(e=>e.key=='notelig').length,summa:cat('Summa Cum Laude'),magna:cat('Magna Cum Laude'),cum:cat('Cum Laude')},
  trends:{Improving:trend('Improving'),Stable:trend('Stable'),Declining:trend('Declining'),'Insufficient Data':trend('Insufficient Data')},support:an.filter(a=>a.status=='needs').length,insufficient:an.filter(a=>a.status=='insufficient').length,
  co:{total:acts.length,verified:acts.filter(a=>a.ver=='Verified').length,pending:acts.filter(a=>a.ver=='Pending Verification').length,rejected:acts.filter(a=>a.ver=='Rejected').length,
   byCategory:Object.fromEntries(CATS.map(c=>[c,acts.filter(a=>a.ver=='Verified'&&a.cat==c).length])),withVerified:co.filter(c=>c.verified>0).length,withNone:co.filter(c=>c.verified==0).length},
  programs:rows}}
// ---- Items that need an administrator's attention (also used by the bell notifications). ----
const pl=(n,one,many)=>n==1?one:many;   // 1 student / 2 students
function actionItems(st){st=st||executiveStats();return[
 {n:st.co.pending,text:pl(st.co.pending,'activity waiting for verification','activities waiting for verification'),href:'cocurricular.php',go:'Co-Curricular'},
 {n:st.honors.review,text:pl(st.honors.review,'honors case needs review (incomplete records)','honors cases need review (incomplete records)'),href:'honors.php',go:'Honors & Awards'},
 {n:st.support,text:pl(st.support,'student may need academic support','students may need academic support'),href:'analytics.php',go:'Predictive Analytics'},
 {n:st.insufficient,text:pl(st.insufficient,'student has too little history for a trend','students have too little history for a trend'),href:'academic-records.php',go:'Academic Records'}].filter(x=>x.n>0)}
// ---- Automatic executive insights: fixed rules, short sentences. level: 'info' | 'attention' ----
function executiveInsights(st){const out=[],pc=(a,b)=>b?Math.round(a/b*100):0,f=n=>n.toFixed(2),add=(level,text,href)=>out.push({level,text,href});
 if(!st.n){add('attention','No students match the current selection.');return out}
 const ps=Object.entries(st.byProgram).sort((a,b)=>b[1]-a[1]);
 add('info',`${st.n} students analyzed (${st.active} active) across ${ps.length} program(s); largest program: ${ps[0][0]} (${ps[0][1]}).`);
 const pg=st.programs.filter(r=>r.avgGwa!=null).sort((a,b)=>a.avgGwa-b.avgGwa);
 if(st.avgGwa!=null)add('info',`Average latest GWA is ${f(st.avgGwa)} (lower is better)`+(pg.length>1?`; best program average: ${pg[0].prog} (${f(pg[0].avgGwa)}), highest: ${pg[pg.length-1].prog} (${f(pg[pg.length-1].avgGwa)}).`:'.'));
 if(st.honors.scope){const h=st.honors;add(h.review?'attention':'info',`${h.eligible} of ${h.scope} graduating students are preliminary honors candidates (Summa ${h.summa}, Magna ${h.magna}, Cum Laude ${h.cum}); ${h.review} ${h.review==1?'needs':'need'} review. Preliminary only, not an official decision.`,'honors.php')}
 const t=st.trends,cl=t.Improving+t.Stable+t.Declining;
 if(cl)add(t.Declining?'attention':'info',`Of ${cl} students with enough history, ${t.Improving} are improving, ${t.Stable} stable and ${t.Declining} declining (${pc(t.Declining,cl)}% declining).`,'analytics.php');
 if(st.support)add('attention',`${st.support} ${pl(st.support,'student may','students may')} need academic support (declining trend, GWA above the watch level, or a failing grade).`,'analytics.php');
 if(st.insufficient||st.noRecords)add('attention',`${st.insufficient} ${pl(st.insufficient,'student has','students have')} too little history for a trend`+(st.noRecords?`, including ${st.noRecords} with no academic records`:'')+'.','academic-records.php');
 const pr=st.byStanding.Probation||0;if(pr)add('info',`${pr} ${pl(pr,'student is','students are')} marked Probation (set manually in the profile).`,'profile.php');
 if(st.autoProbation)add('attention',`${st.autoProbation} ${pl(st.autoProbation,'student meets','students meet')} the Handbook probation criterion (two grades of 5.00 in the latest semester); the Dean's recommendation is required.`,'academic-records.php');
 if(st.co.total){add(st.co.pending?'attention':'info',`${st.co.verified} of ${st.co.total} activities are verified (${pc(st.co.verified,st.co.total)}%); ${st.co.pending} pending, ${st.co.rejected} rejected.`,'cocurricular.php');if(st.co.withNone)add('info',`${st.co.withNone} of ${st.n} students have no verified co-curricular activity yet.`,'cocurricular.php')}
 else add('info','No co-curricular activities have been recorded for this selection.','cocurricular.php');
 return out}

// =====================================================================================
// ACADEMIC STANDING.  The "Standing" field in the student profile is set MANUALLY by an administrator.
// The only automatic check is the Handbook's probation criterion (Retention Policies): two final grades of 5.00 in a
// semester -> probation "upon the recommendation of the Dean". It is shown as an indicator; it never changes the profile.
// Provisional: confirm with PLMun. No other standing rule is implemented.
// =====================================================================================
const STANDING_RULE={failGrade:5.00,failCount:2};
function probationCheck(sid){const p=semesterSeries(sid).filter(x=>x.n>0);if(!p.length)return{met:false,count:0,term:null};const l=p[p.length-1];
 const c=l.recs.filter(r=>r.grade>=STANDING_RULE.failGrade-1e-9).length;return{met:c>=STANDING_RULE.failCount,count:c,term:l.name}}
