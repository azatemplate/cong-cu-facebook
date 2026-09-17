import zipfile

zip_path = r"d:\pagespeed\hi\facebook\facebook-ver10.49.zip"

with zipfile.ZipFile(zip_path, 'r') as z:
    with z.open("cron/publish_worker.php") as src, open(r"d:\pagespeed\hi\facebook\cron\publish_worker.php", "wb") as dst:
        dst.write(src.read())
        print("Restored exact root cron/publish_worker.php from facebook-ver10.49.zip!")
        
    with z.open("cron/start_publish.php") as src, open(r"d:\pagespeed\hi\facebook\cron\start_publish.php", "wb") as dst:
        dst.write(src.read())
        print("Restored exact root cron/start_publish.php from facebook-ver10.49.zip!")
