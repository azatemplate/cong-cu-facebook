import re

input_sql = r"d:\pagespeed\hi\facebook\facebooksever.sql"
output_sql = r"d:\pagespeed\hi\facebook\facebooksever_clean.sql"

tables_to_clear_data = {
    'scheduled_posts',
    'posts_history',
    'page_notifications',
    'ai_usage_logs',
    'fb_customers',
    'fb_conversations',
    'posted_folder_files',
    'auto_replied_comments',
    'dashboard_snapshots',
    'fetched_fanpage_posts'
}

skipping = False
skipped_lines = 0

with open(input_sql, "r", encoding="utf-8", errors="ignore") as fin, \
     open(output_sql, "w", encoding="utf-8") as fout:
    for line in fin:
        if line.startswith("INSERT INTO"):
            match = re.search(r"INSERT INTO `?(\w+)`?", line, re.IGNORECASE)
            if match:
                tbl = match.group(1).lower()
                if tbl in tables_to_clear_data:
                    skipping = True
                else:
                    skipping = False

        if skipping:
            skipped_lines += 1
            if line.endswith(";\n") or line.endswith(";\r\n"):
                skipping = False
            continue

        fout.write(line)

print(f"Skipped {skipped_lines} lines.")
