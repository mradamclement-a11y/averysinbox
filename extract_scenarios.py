"""Extract SCENARIOS from inbox.html and write scenarios.json. Pure Python (no Node)."""
import re
import json
from pathlib import Path

path = Path(__file__).parent
html_path = path / "inbox.html"
out_path = path / "scenarios.json"

html = html_path.read_text(encoding="utf-8")
start = html.find("const SCENARIOS = [")
if start == -1:
    raise SystemExit("SCENARIOS not found")
from_pos = start + len("const SCENARIOS = ")

# Find matching closing ];
depth = 0
i = from_pos
in_string = None
escape = False
end_pos = -1
while i < len(html):
    c = html[i]
    if escape:
        escape = False
        i += 1
        continue
    if in_string:
        if c == "\\":
            escape = True
        elif c == in_string:
            in_string = None
    else:
        if c in ("'", '"'):
            in_string = c
        elif c == "[":
            depth += 1
        elif c == "]":
            depth -= 1
            if depth == 0:
                end_pos = i + 1
                break
    i += 1
if end_pos == -1:
    raise SystemExit("Could not find end of SCENARIOS array")

js = html[from_pos:end_pos]

def js_to_json(js_str):
    # 1) Quote unquoted object keys (identifier: -> "identifier":)
    # Match word chars before : that are not already in quotes. We do this by
    # replacing pattern ([,{]\s*)([a-zA-Z_][a-zA-Z0-9_]*)(\s*:) with \1"\2"\3
    result = []
    i = 0
    n = len(js_str)
    in_sq = False
    in_dq = False
    escape = False
    depth_brace = 0
    depth_bracket = 0
    key_allowed = True  # after , or { or [
    while i < n:
        c = js_str[i]
        if escape:
            result.append(c)
            escape = False
            i += 1
            continue
        if in_sq:
            if c == "\\":
                escape = True
                result.append(c)
            elif c == "'":
                in_sq = False
                result.append(c)
            else:
                result.append(c)
            i += 1
            continue
        if in_dq:
            if c == "\\":
                escape = True
                result.append(c)
            elif c == '"':
                in_dq = False
                result.append(c)
            else:
                result.append(c)
            i += 1
            continue
        if c == "'":
            result.append('"')
            i += 1
            while i < n:
                if js_str[i] == "\\" and i + 1 < n:
                    next_c = js_str[i + 1]
                    if next_c == "'":
                        result.append("'")
                        i += 2
                    elif next_c == "\\":
                        result.append("\\\\")
                        i += 2
                    elif next_c == '"':
                        result.append('\\"')
                        i += 2
                    else:
                        result.append("\\")
                        result.append(next_c)
                        i += 2
                elif js_str[i] == '"':
                    result.append('\\"')
                    i += 1
                elif js_str[i] == "'":
                    result.append('"')
                    i += 1
                    break
                else:
                    result.append(js_str[i])
                    i += 1
            continue
        if c == '"':
            in_dq = True
            result.append(c)
            i += 1
            continue
        if c in ",{[":
            key_allowed = True
            result.append(c)
            i += 1
            continue
        if c in "}]":
            result.append(c)
            i += 1
            continue
        if key_allowed and c in " \t\n\r":
            result.append(c)
            i += 1
            continue
        # Check for unquoted key (identifier followed by :)
        if key_allowed:
            m = re.match(r"([a-zA-Z_][a-zA-Z0-9_]*)(\s*:)", js_str[i:])
            if m:
                result.append('"')
                result.append(m.group(1))
                result.append('"')
                result.append(m.group(2))
                i += m.end()
                key_allowed = False
                continue
        key_allowed = False
        result.append(c)
        i += 1
    return "".join(result)

json_str = js_to_json(js)
# Replace null for sig: null
json_str = re.sub(r":\s*null\b", ": null", json_str)
# Remove trailing commas (legal in JS, illegal in JSON); repeat for nested structures
for _ in range(20):
    prev = json_str
    json_str = re.sub(r",\s*]", "]", json_str)
    json_str = re.sub(r",\s*}", "}", json_str)
    if json_str == prev:
        break
data = json.loads(json_str)
out_path.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding="utf-8")
print(f"Written {len(data)} scenarios to scenarios.json")
