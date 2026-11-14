// מייצר report.html עצמאי (בלי שרת) מתוך רשימת הלידים.

const CATEGORY_LABELS = {
  direct: "חברה ישירה",
  "via-recruiter": "דרך השמה / חסוי",
  agency: "סוכנות",
  irrelevant: "לא רלוונטי",
};

export function renderReport(leads, { generatedAt, hotLeadScore }) {
  const data = JSON.stringify(
    leads.map((l) => ({
      id: l.id,
      source: l.source,
      title: l.title,
      company: l.company,
      companyHidden: l.companyHidden,
      city: l.city,
      description: l.description,
      publishedAt: l.publishedAt,
      firstSeen: l.firstSeen,
      url: l.url,
      keywords: l.keywords,
      c: l.classification,
      contactLinks: l.contactLinks,
      email: l.email,
    }))
  ).replace(/</g, "\\u003c");

  return `<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>סורק לידים</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f5f4f0; --surface:#ffffff; --surface-2:#f0eee8; --ink:#1c1b19; --ink-2:#5c5a55; --ink-3:#8a8780;
  --line:#e3e0d8; --accent:#d9480f; --accent-ink:#ffffff; --hot:#d9480f; --good:#2b8a3e; --mid:#b08900; --low:#8a8780;
  --chip:#f3ece4; --chip-ink:#7a3d12; --shadow:0 1px 2px rgba(0,0,0,.05),0 4px 16px rgba(0,0,0,.04);
}
@media (prefers-color-scheme: dark){ :root:not([data-theme="light"]){
  --bg:#141413; --surface:#1d1c1a; --surface-2:#262522; --ink:#efece6; --ink-2:#b5b1a8; --ink-3:#85827a;
  --line:#33312d; --accent:#ff7a3d; --accent-ink:#1a0d05; --hot:#ff7a3d; --good:#69db7c; --mid:#ffd43b; --low:#85827a;
  --chip:#2d2520; --chip-ink:#ffb487; --shadow:none;
}}
:root[data-theme="dark"]{
  --bg:#141413; --surface:#1d1c1a; --surface-2:#262522; --ink:#efece6; --ink-2:#b5b1a8; --ink-3:#85827a;
  --line:#33312d; --accent:#ff7a3d; --accent-ink:#1a0d05; --hot:#ff7a3d; --good:#69db7c; --mid:#ffd43b; --low:#85827a;
  --chip:#2d2520; --chip-ink:#ffb487; --shadow:none;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 Heebo,system-ui,-apple-system,"Segoe UI",Arial,sans-serif}
.wrap{max-width:1080px;margin:0 auto;padding:28px 16px 64px}
header h1{font-size:28px;font-weight:800;margin:0 0 4px;letter-spacing:-.01em}
header p{margin:0;color:var(--ink-2)}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:22px 0 18px}
.stat{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:12px 14px;box-shadow:var(--shadow)}
.stat b{display:block;font-size:26px;font-weight:800;font-variant-numeric:tabular-nums}
.stat span{color:var(--ink-2);font-size:13px}
.stat.hot b{color:var(--hot)}
.toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;position:sticky;top:0;background:var(--bg);padding:10px 0;z-index:5}
.tabs{display:flex;gap:4px;flex-wrap:wrap}
.tab{border:1px solid var(--line);background:var(--surface);color:var(--ink-2);border-radius:999px;padding:6px 12px;font:inherit;font-size:14px;cursor:pointer}
.tab[aria-pressed="true"]{background:var(--ink);color:var(--bg);border-color:var(--ink)}
.tab .n{opacity:.65;margin-inline-start:4px;font-variant-numeric:tabular-nums}
.search{flex:1;min-width:180px;border:1px solid var(--line);background:var(--surface);color:var(--ink);border-radius:10px;padding:8px 12px;font:inherit}
.list{display:flex;flex-direction:column;gap:10px;margin-top:8px}
.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px;box-shadow:var(--shadow);display:grid;grid-template-columns:56px 1fr;gap:14px}
.card.done{opacity:.55}
.score{width:56px;height:56px;border-radius:12px;display:grid;place-items:center;font-weight:800;font-size:20px;background:var(--surface-2);font-variant-numeric:tabular-nums}
.score.s-hot{background:var(--hot);color:var(--accent-ink)}
.score.s-good{color:var(--good)} .score.s-mid{color:var(--mid)} .score.s-low{color:var(--low)}
.top{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap}
.company{font-weight:700;font-size:16px}
.title{color:var(--ink-2)}
.meta{color:var(--ink-3);font-size:13px;margin-top:2px}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.chip{background:var(--chip);color:var(--chip-ink);border-radius:999px;padding:2px 9px;font-size:12.5px}
.chip.cat{background:var(--surface-2);color:var(--ink-2)}
.hint{margin-top:8px;font-size:14px}
.hint b{color:var(--accent)}
.pitch{margin-top:6px;font-size:14px;color:var(--ink-2)}
details{margin-top:10px;border-top:1px solid var(--line);padding-top:8px}
summary{cursor:pointer;color:var(--ink-2);font-size:14px}
.reasons{margin:6px 0 0;padding-inline-start:18px;color:var(--ink-2);font-size:13.5px}
.desc{white-space:pre-wrap;color:var(--ink-2);font-size:13.5px;margin-top:6px;max-height:220px;overflow:auto}
.email{background:var(--surface-2);border-radius:10px;padding:12px;margin-top:8px;white-space:pre-wrap;font-size:14px}
.email .subj{font-weight:700;margin-bottom:8px}
.actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;align-items:center}
.btn{border:1px solid var(--line);background:var(--surface);color:var(--ink);border-radius:8px;padding:6px 11px;font:inherit;font-size:13.5px;cursor:pointer;text-decoration:none}
.btn.primary{background:var(--accent);color:var(--accent-ink);border-color:var(--accent)}
select.btn{padding:5px 8px}
.empty{color:var(--ink-3);text-align:center;padding:40px}
footer{color:var(--ink-3);font-size:12.5px;margin-top:28px}
@media (max-width:640px){
  .stats{grid-template-columns:repeat(2,1fr)}
  .card{grid-template-columns:1fr}
  .score{width:auto;height:36px;justify-self:start;padding:0 12px}
}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>סורק לידים ממודעות דרושים</h1>
    <p>עסקים שמגייסים קמפיינר או איש דיגיטל, ואפשר להציע להם צוות מלא במיקור חוץ. סוכנויות מסוננות החוצה.</p>
  </header>
  <section class="stats" id="stats"></section>
  <div class="toolbar">
    <div class="tabs" id="tabs" role="toolbar"></div>
    <input class="search" id="q" type="search" placeholder="חיפוש חברה, תפקיד, עיר...">
  </div>
  <main class="list" id="list"></main>
  <footer>עודכן: ${new Date(generatedAt).toLocaleString("he-IL")} · הסטטוסים נשמרים בדפדפן הזה בלבד</footer>
</div>
<script>
const LEADS = ${data};
const HOT = ${Number(hotLeadScore)};
const CAT = ${JSON.stringify(CATEGORY_LABELS)};
const STATUSES = ["חדש","לבדיקה","נשלח מייל","נענה","נסגר","לא מתאים"];
const store = {
  get(k){ try{ return JSON.parse(localStorage.getItem("jls:"+k)); }catch{ return null; } },
  set(k,v){ try{ localStorage.setItem("jls:"+k, JSON.stringify(v)); }catch{} }
};
const statusOf = id => (store.get("status") || {})[id] || "חדש";
function setStatus(id, s){ const all = store.get("status") || {}; all[id] = s; store.set("status", all); }

const TABS = [
  ["hot","🔥 לידים חמים", l => l.c.score >= HOT],
  ["direct","חברות ישירות", l => l.c.category === "direct"],
  ["via-recruiter","דרך השמה / חסוי", l => l.c.category === "via-recruiter"],
  ["agency","סוכנויות", l => l.c.category === "agency"],
  ["irrelevant","לא רלוונטי", l => l.c.category === "irrelevant"],
  ["all","הכל", () => true],
];
let tab = store.get("tab") || "hot";
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const fmtDate = d => d ? new Date(d).toLocaleDateString("he-IL") : "";
const scoreClass = s => s >= HOT ? "s-hot" : s >= 45 ? "s-good" : s > 0 ? "s-mid" : "s-low";

function renderStats(){
  const n = f => LEADS.filter(f).length;
  document.getElementById("stats").innerHTML = [
    ["hot", n(TABS[0][2]), "לידים חמים"],
    ["", n(TABS[1][2]), "חברות ישירות"],
    ["", n(TABS[2][2]), "דרך השמה / חסוי"],
    ["", n(TABS[3][2]), "סוכנויות שסוננו"],
  ].map(([c,v,l]) => '<div class="stat '+c+'"><b>'+v+'</b><span>'+l+'</span></div>').join("");
}
function renderTabs(){
  document.getElementById("tabs").innerHTML = TABS.map(([k,label,f]) =>
    '<button class="tab" data-k="'+k+'" aria-pressed="'+(k===tab)+'">'+label+'<span class="n">'+LEADS.filter(f).length+'</span></button>').join("");
}
function gmailUrl(e){ return "https://mail.google.com/mail/?view=cm&fs=1&su="+encodeURIComponent(e.subject)+"&body="+encodeURIComponent(e.body); }

function card(l){
  const st = statusOf(l.id);
  const who = l.companyHidden ? "מפרסם חסוי" : l.company;
  const chips = [
    '<span class="chip cat">'+esc(CAT[l.c.category])+'</span>',
    l.c.industry ? '<span class="chip cat">'+esc(l.c.industry)+'</span>' : "",
    ...(l.c.needs||[]).map(n => '<span class="chip">'+esc(n)+'</span>')
  ].join("");
  const hint = l.c.endClientHint ? '<div class="hint">המעסיק האמיתי: <b>'+esc(l.c.endClientHint)+'</b></div>' : "";
  const pitch = l.c.pitchAngle ? '<div class="pitch">💡 '+esc(l.c.pitchAngle)+'</div>' : "";
  const links = (l.contactLinks||[]).map(x => '<a class="btn" target="_blank" rel="noopener" href="'+esc(x.url)+'">'+esc(x.label)+'</a>').join("");
  const email = l.email ? '<details><summary>✉️ טיוטת מייל</summary><div class="email"><div class="subj">'+esc(l.email.subject)+'</div>'+esc(l.email.body)+'</div>'
    + '<div class="actions"><button class="btn primary" data-copy="'+esc(l.id)+'">העתקת המייל</button><a class="btn" target="_blank" rel="noopener" href="'+esc(gmailUrl(l.email))+'">פתיחה ב-Gmail</a></div></details>' : "";
  return '<article class="card'+(["נסגר","לא מתאים"].includes(st)?" done":"")+'">'
    + '<div class="score '+scoreClass(l.c.score)+'" title="ציון ליד">'+l.c.score+'</div>'
    + '<div><div class="top"><span class="company">'+esc(who)+'</span><span class="title">'+esc(l.title)+'</span></div>'
    + '<div class="meta">'+esc([l.city, fmtDate(l.publishedAt), l.source, l.c.method==="claude"?"סווג ע״י Claude":"סווג לפי חוקים"].filter(Boolean).join(" · "))+'</div>'
    + '<div class="chips">'+chips+'</div>'+hint+pitch
    + '<div class="actions"><a class="btn" target="_blank" rel="noopener" href="'+esc(l.url)+'">למודעה</a>'+links
    + '<select class="btn" data-status="'+esc(l.id)+'" aria-label="סטטוס">'+STATUSES.map(s => '<option'+(s===st?" selected":"")+'>'+s+'</option>').join("")+'</select></div>'
    + email
    + '<details><summary>למה הסיווג הזה? ותיאור המשרה</summary><ul class="reasons">'+(l.c.reasons||[]).map(r => '<li>'+esc(r)+'</li>').join("")+'</ul><div class="desc">'+esc(l.description)+'</div></details>'
    + '</div></article>';
}
function render(){
  const q = document.getElementById("q").value.trim().toLowerCase();
  const f = TABS.find(t => t[0] === tab)[2];
  const items = LEADS.filter(f).filter(l => !q || [l.company,l.title,l.city,l.c.industry,l.c.endClientHint].join(" ").toLowerCase().includes(q));
  document.getElementById("list").innerHTML = items.length ? items.map(card).join("") : '<div class="empty">אין לידים בלשונית הזו</div>';
}
document.getElementById("tabs").addEventListener("click", e => { const b = e.target.closest(".tab"); if(!b) return; tab = b.dataset.k; store.set("tab", tab); renderTabs(); render(); });
document.getElementById("q").addEventListener("input", render);
document.getElementById("list").addEventListener("change", e => { const id = e.target.dataset.status; if(id){ setStatus(id, e.target.value); render(); }});
document.getElementById("list").addEventListener("click", async e => {
  const id = e.target.dataset.copy; if(!id) return;
  const l = LEADS.find(x => x.id === id);
  try { await navigator.clipboard.writeText("נושא: "+l.email.subject+"\\n\\n"+l.email.body); e.target.textContent = "הועתק ✓"; }
  catch { e.target.textContent = "ההעתקה נחסמה"; }
  setTimeout(() => e.target.textContent = "העתקת המייל", 1800);
});
if(!TABS.some(t => t[0] === tab)) tab = "hot";
renderStats(); renderTabs(); render();
</script>
</body>
</html>`;
}
