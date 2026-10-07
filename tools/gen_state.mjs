import { readFileSync } from "node:fs";

const blueprint = JSON.parse(readFileSync("/home/danielsudenfield/chirothintracker_react/frontend/src/lib/intake/intake-blueprint.json", "utf8"));

function allFields() {
  const out = [];
  for (const step of blueprint.steps) out.push(...step.fields);
  return out;
}

const fields = allFields();
const state = {};

for (const f of fields) {
  if (f.widget === "static") continue;
  switch (f.widget) {
    case "consent":
      state[f.name] = f.name === "field_consent" ? "Testy McTest" : 1;
      break;
    case "checkbox":
      state[f.name] = true;
      break;
    case "checkbox-grid":
      state[f.name] = [f.options[0]?.value ?? "1"];
      break;
    case "radio":
      state[f.name] = f.options[0]?.value ?? "0";
      break;
    case "select":
      state[f.name] = f.options[0]?.value ?? "";
      break;
    case "number":
      state[f.name] = f.drupalType === "integer" ? "2010" : "100";
      break;
    case "date":
      state[f.name] = "2026-09-26";
      break;
    case "email":
      state[f.name] = "testy@example.com";
      break;
    case "repeatable":
      state[f.name] = ["Take one"];

      break;
    default:
      if (f.part) {
        if (f.drupalType === "address") {
          const part = f.part;
          const value =
            part === "country_code" ? "US"
            : part === "administrative_area" ? "OH"
            : part === "locality" ? "Columbus"
            : part === "postal_code" ? "43215"
            : part === "address_line1" ? "123 Main St"
            : "";
          state[f.name] = value;
          break;
        }
        state[f.name] = "abc";
        break;
      }
      state[f.name] = "test value";
  }
}

console.log("fields in state:", Object.keys(state).length);
console.log(JSON.stringify({ fields: state }, null, 0));