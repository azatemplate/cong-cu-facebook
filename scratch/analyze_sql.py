import re

sql_file = r"d:\pagespeed\hi\facebook\facebooksever.sql"

table_sizes = {}
current_table = None

with open(sql_file, "r", encoding="utf-8", errors="ignore") as f:
    for line in f:
        l_len = len(line)
        if line.startswith("INSERT INTO"):
            match = re.search(r"INSERT INTO `?(\w+)`?", line, re.IGNORECASE)
            if match:
                current_table = match.group(1).lower()
                table_sizes[current_table] = table_sizes.get(current_table, 0) + l_len
        elif current_table:
            table_sizes[current_table] += l_len

print("=== TOTAL TABLE SIZES IN DUMP (MB) ===")
sorted_tables = sorted(table_sizes.items(), key=lambda x: x[1], reverse=True)
for tbl, size in sorted_tables:
    mb = size / (1024 * 1024)
    print(f"{tbl}: {mb:.2f} MB")
