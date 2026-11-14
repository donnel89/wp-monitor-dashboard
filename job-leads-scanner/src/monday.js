// סנכרון לידים לבורד במאנדיי דרך ה-GraphQL API.
// דורש MONDAY_API_TOKEN (במאנדיי: תמונת הפרופיל ← Developers ← My access tokens).
// כל ליד נוצר פעם אחת בלבד. אחרי שנוצר, הסורק לא דורס שינויים שאיש המכירות עשה בבורד.
import { config } from "./config.js";

const API = "https://api.monday.com/v2";

const KIND_LABEL = {
  tender: "מכרז",
  direct: "משרה – חברה ישירה",
  "via-recruiter": "משרה – דרך השמה",
};

const day = (iso) => (iso ? String(iso).slice(0, 10) : null);

export function shouldSync(lead) {
  const c = lead.classification;
  return c.score >= config.monday.minScore && Boolean(KIND_LABEL[c.category]);
}

export function itemName(lead) {
  const who = lead.companyHidden ? lead.classification.endClientHint || "מפרסם חסוי" : lead.company;
  return `${who} – ${lead.title}`.slice(0, 250);
}

export function groupFor(lead) {
  const g = config.monday.groups;
  const c = lead.classification;
  if (c.category === "tender") return g.tender;
  return c.score >= config.hotLeadScore ? g.hot : g.other;
}

export function columnValues(lead) {
  const col = config.monday.columns;
  const c = lead.classification;
  const v = {
    [col.status]: { label: "חדש" },
    [col.kind]: { label: KIND_LABEL[c.category] },
    [col.score]: String(c.score),
    [col.company]: lead.companyHidden ? "חסוי" : lead.company,
    [col.leadId]: lead.id,
  };
  if (config.monday.assigneeId) v[col.assignee] = { personsAndTeams: [{ id: config.monday.assigneeId, kind: "person" }] };
  if (c.endClientHint) v[col.endClient] = c.endClientHint;
  if (lead.contactName) v[col.contactName] = lead.contactName;
  if (lead.contactEmail) v[col.email] = { email: lead.contactEmail, text: lead.contactEmail };
  if (lead.deadline) v[col.deadline] = { date: day(lead.deadline) };
  if (lead.url) v[col.url] = { url: lead.url, text: c.category === "tender" ? "למכרז" : "למודעה" };
  const search = lead.contactLinks?.find((l) => l.label.includes("לינקדאין")) ?? lead.contactLinks?.[0];
  if (search) v[col.contactSearch] = { url: search.url, text: "חיפוש איש קשר" };
  if (c.industry) v[col.industry] = c.industry;
  if (lead.city) v[col.city] = lead.city;
  if (c.needs?.length) v[col.needs] = { labels: c.needs };
  if (lead.publishedAt) v[col.publishedAt] = { date: day(lead.publishedAt) };
  const why = [c.pitchAngle && `💡 ${c.pitchAngle}`, ...(c.reasons ?? []).map((r) => `• ${r}`)].filter(Boolean);
  if (why.length) v[col.reasons] = { text: why.join("\n") };
  if (lead.email) {
    v[col.emailSubject] = lead.email.subject;
    v[col.emailBody] = { text: lead.email.body };
  }
  return v;
}

export const CREATE_ITEM = `mutation ($board: ID!, $group: String, $name: String!, $values: JSON!) {
  create_item(board_id: $board, group_id: $group, item_name: $name, column_values: $values, create_labels_if_missing: true) { id }
}`;

export const FIND_BY_LEAD_ID = `query ($board: ID!, $col: String!, $ids: [String]!) {
  items_page_by_column_values(board_id: $board, limit: 100, columns: [{ column_id: $col, column_values: $ids }]) {
    items { id column_values(ids: [$col]) { text } }
  }
}`;

export function createItemVariables(lead) {
  return {
    board: String(config.monday.boardId),
    group: groupFor(lead) || null,
    name: itemName(lead),
    values: JSON.stringify(columnValues(lead)),
  };
}

async function gql(query, variables) {
  const res = await fetch(API, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: process.env.MONDAY_API_TOKEN,
    },
    body: JSON.stringify({ query, variables }),
  });
  const json = await res.json();
  if (!res.ok || json.errors?.length) {
    throw new Error(`monday: ${JSON.stringify(json.errors ?? json).slice(0, 300)}`);
  }
  return json.data;
}

// מחזיר כמה פריטים נוצרו. מעדכן lead.mondayItemId בתוך ה-store.
export async function syncToMonday(leads, log = console.log) {
  if (!process.env.MONDAY_API_TOKEN) {
    log("  ⚠ MONDAY_API_TOKEN לא מוגדר. מדלג על סנכרון למאנדיי");
    return 0;
  }
  const pending = leads.filter((l) => shouldSync(l) && !l.mondayItemId);
  if (!pending.length) return 0;

  // הגנה מכפילויות גם אם data/leads.json נמחק: בודקים מה כבר קיים בבורד לפי "מזהה ליד"
  const existing = new Map();
  for (let i = 0; i < pending.length; i += 50) {
    const ids = pending.slice(i, i + 50).map((l) => l.id);
    const data = await gql(FIND_BY_LEAD_ID, {
      board: String(config.monday.boardId),
      col: config.monday.columns.leadId,
      ids,
    });
    for (const it of data.items_page_by_column_values.items) existing.set(it.column_values[0]?.text, it.id);
  }

  let created = 0;
  for (const lead of pending) {
    if (existing.has(lead.id)) {
      lead.mondayItemId = existing.get(lead.id);
      continue;
    }
    try {
      const data = await gql(CREATE_ITEM, createItemVariables(lead));
      lead.mondayItemId = data.create_item.id;
      created++;
    } catch (e) {
      log(`  ⚠ ${itemName(lead)}: ${e.message}`);
    }
  }
  return created;
}
