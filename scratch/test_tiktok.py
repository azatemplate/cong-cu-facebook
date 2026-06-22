import urllib.request
import urllib.parse
import json
import ssl

def fetch_sec_uid(username):
    username = username.lstrip("@").strip()
    # 1. Alisg
    url = f"https://api22-normal-c-alisg.tiktokv.com/aweme/v1/user/profile/other/?unique_id={username}&device_type=SM-ASUS_Z01QD&device_platform=android&iid=7318518857994389254&device_id=7318517321748022790&version_code=300904&app_name=musical_ly"
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36",
            "Referer": "https://www.tiktok.com/"
        }
    )
    # Ignore SSL verification
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    
    print("Trying Alisg endpoint...")
    try:
        with urllib.request.urlopen(req, context=ctx, timeout=5) as response:
            data = json.loads(response.read().decode())
            user = data.get("user", {})
            sec_uid = user.get("sec_uid", "")
            print(f"Alisg sec_uid: {sec_uid}")
            if sec_uid:
                return sec_uid
    except Exception as e:
        print(f"Alisg error: {e}")
        
    # 2. TikWM
    tikwm_url = f"https://www.tikwm.com/api/user/info?unique_id={username}"
    print(f"Trying TikWM endpoint: {tikwm_url}")
    try:
        req = urllib.request.Request(tikwm_url, headers={"User-Agent": "Mozilla/5.0"})
        with urllib.request.urlopen(req, context=ctx, timeout=6) as response:
            data = json.loads(response.read().decode())
            print(f"TikWM code: {data.get('code')}, has_data: {'data' in data}")
            if data.get("code") == 0 and data.get("data"):
                user_obj = data["data"].get("user", {})
                sec_uid = user_obj.get("secUid", "")
                print(f"TikWM sec_uid: {sec_uid}")
                return sec_uid
    except Exception as e:
        print(f"TikWM error: {e}")
        
    return ""

print("Result:", fetch_sec_uid("copphavietcom"))
