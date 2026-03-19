import { readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";

const root = process.cwd();
const localesRoot = path.join(root, "resources", "js", "i18n", "locales");
const baseLocale = "en";
const compareLocale = "es";

function flattenKeys(value, prefix = "") {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    return prefix ? [prefix] : [];
  }

  const entries = [];
  for (const [key, child] of Object.entries(value)) {
    const next = prefix ? `${prefix}.${key}` : key;
    if (child && typeof child === "object" && !Array.isArray(child)) {
      entries.push(...flattenKeys(child, next));
      continue;
    }

    entries.push(next);
  }

  return entries;
}

function readJson(filePath) {
  return JSON.parse(readFileSync(filePath, "utf8"));
}

function collectLocaleFiles(localeDir) {
  return readdirSync(localeDir)
    .filter((entry) => entry.endsWith(".json"))
    .map((entry) => path.join(localeDir, entry))
    .filter((filePath) => statSync(filePath).isFile())
    .sort();
}

const baseDir = path.join(localesRoot, baseLocale);
const compareDir = path.join(localesRoot, compareLocale);

const baseFiles = collectLocaleFiles(baseDir);
let hasMismatch = false;

for (const baseFile of baseFiles) {
  const namespace = path.basename(baseFile);
  const compareFile = path.join(compareDir, namespace);

  let baseJson;
  let compareJson;

  try {
    baseJson = readJson(baseFile);
  } catch (error) {
    console.error(`Failed to parse ${baseFile}:`, error.message);
    process.exitCode = 1;
    continue;
  }

  try {
    compareJson = readJson(compareFile);
  } catch (error) {
    console.error(`Missing or invalid locale file ${compareFile}:`, error.message);
    hasMismatch = true;
    continue;
  }

  const baseKeys = new Set(flattenKeys(baseJson));
  const compareKeys = new Set(flattenKeys(compareJson));

  const missingInCompare = [...baseKeys].filter((key) => !compareKeys.has(key));
  const extraInCompare = [...compareKeys].filter((key) => !baseKeys.has(key));

  if (missingInCompare.length > 0 || extraInCompare.length > 0) {
    hasMismatch = true;
    console.error(`Namespace ${namespace} has key mismatch:`);

    if (missingInCompare.length > 0) {
      console.error(`  Missing in ${compareLocale}:`);
      for (const key of missingInCompare) {
        console.error(`    - ${key}`);
      }
    }

    if (extraInCompare.length > 0) {
      console.error(`  Extra in ${compareLocale}:`);
      for (const key of extraInCompare) {
        console.error(`    - ${key}`);
      }
    }
  }
}

if (hasMismatch) {
  process.exitCode = 1;
} else {
  console.log(`i18n key parity check passed (${baseLocale} vs ${compareLocale}).`);
}
