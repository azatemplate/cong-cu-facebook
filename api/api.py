import re
import time
import random
import httpx
import uvicorn
from fastapi import FastAPI, Query, HTTPException, Request
from fastapi.responses import JSONResponse

app = FastAPI(title="TikTok API Server Lite")

# Default headers to mimic official TikTok App request
TIKTOK_HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36",
    "Referer": "https://www.tiktok.com/",
    "Cookie": "CykaBlyat=XD"
}

class ProxyManager:
    def __init__(self):
        self.master_key = "AyrLPrQDMOIujeiSAfixaG"
        self.keys_url = f"https://proxy.vn/proxyxoay/apigetkeyxoay.php?key={self.master_key}"
        self.proxy_cache = {}  # keyxoay -> {"ip_port": "...", "expires_at": ...}
        self.last_keys_fetch = 0
        self.active_keys = []

    async def get_keys(self):
        now = time.time()
        if now - self.last_keys_fetch < 300 and self.active_keys:
            return self.active_keys

        try:
            async with httpx.AsyncClient(timeout=5.0, verify=False) as client:
                resp = await client.get(self.keys_url)
                if resp.status_code == 200:
                    text = resp.text.strip()
                    matches = re.findall(r'\{[^{}]+\}', text)
                    keys = []
                    for m in matches:
                        try:
                            import json
                            data = json.loads(m)
                            if data.get("status") == 100 and data.get("keyxoay"):
                                keys.append(data["keyxoay"])
                        except Exception:
                            pass
                    if keys:
                        self.active_keys = keys
                        self.last_keys_fetch = now
                        print(f"[ProxyManager] Loaded active keys: {keys}")
        except Exception as e:
            print(f"[ProxyManager] Error loading keys: {e}")

        return self.active_keys

    async def get_active_proxy(self):
        keys = await self.get_keys()
        if not keys:
            return None

        shuffled_keys = list(keys)
        random.shuffle(shuffled_keys)
        now = time.time()

        for key in shuffled_keys:
            cache = self.proxy_cache.get(key)
            if cache and now < cache["expires_at"]:
                return cache["ip_port"]

            get_url = f"https://proxyxoay.shop/api/get.php?key={key}&&nhamang=random&&tinhthanh=0&whitelist="
            try:
                async with httpx.AsyncClient(timeout=5.0, verify=False) as client:
                    resp = await client.get(get_url)
                    if resp.status_code == 200:
                        data = resp.json()
                        if data.get("status") == 100 and data.get("proxyhttp"):
                            raw_proxy = data["proxyhttp"]
                            clean_proxy = raw_proxy.rstrip(":")
                            if clean_proxy:
                                self.proxy_cache[key] = {
                                    "ip_port": clean_proxy,
                                    "expires_at": now + 180
                                }
                                print(f"[ProxyManager] New proxy for key {key}: {clean_proxy}")
                                return clean_proxy
                        elif data.get("status") == 101:
                            print(f"[ProxyManager] Key {key} error: {data.get('comen')}")
                        else:
                            if cache:
                                print(f"[ProxyManager] Rate limited. Reusing expired proxy for key {key}.")
                                cache["expires_at"] = now + 15
                                return cache["ip_port"]
            except Exception as e:
                print(f"[ProxyManager] Error getting proxy for key {key}: {e}")
                if cache:
                    return cache["ip_port"]

        for key, cache in self.proxy_cache.items():
            if cache.get("ip_port"):
                return cache["ip_port"]

        return None

    def invalidate_proxy(self, ip_port):
        if not ip_port:
            return
        for key, cache in list(self.proxy_cache.items()):
            if cache.get("ip_port") == ip_port:
                print(f"[ProxyManager] Invalidating bad/rate-limited proxy: {ip_port}")
                del self.proxy_cache[key]
                break

proxy_manager = ProxyManager()

TIKTOK_APP_HEADERS = {
    "User-Agent": "com.zhiliaoapp.musically/2022600030 (Linux; U; Android 7.1.2; ru_RU; Rootkit; Build/NJH47F; Cronet/TTNetVersion:b4d74d15 2020-04-23 QuicVersion:0144d138 2020-03-24)"
}

async def resolve_url(url: str) -> str:
    """Resolve short URLs and redirects to get the final TikTok URL."""
    proxy = await proxy_manager.get_active_proxy()
    client_kwargs = {"follow_redirects": True, "timeout": 3.0, "verify": False}
    if proxy:
        client_kwargs["proxy"] = f"http://{proxy}"
    async with httpx.AsyncClient(**client_kwargs) as client:
        # Perform a HEAD request to quickly follow redirects without downloading the body
        response = await client.head(url, headers={"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"})
        return str(response.url)

def extract_video_id(url: str) -> str:
    """Extract TikTok Video ID from URL."""
    # Match video/1234567890
    match = re.search(r"video/(\d+)", url)
    if match:
        return match.group(1)
    
    # Match v=1234567890 (sometimes found in alternative URLs)
    match = re.search(r"v=(\d+)", url)
    if match:
        return match.group(1)
        
    return ""

def extract_high_quality_urls(video_info: dict) -> tuple:
    """Extract HD (720p) and FHD (1080p) URLs from the video's bit_rate list."""
    fhd_url = None
    hd_url = None
    
    bit_rates = video_info.get("bit_rate", [])
    if isinstance(bit_rates, list):
        for item in bit_rates:
            if not isinstance(item, dict):
                continue
            gear_name = str(item.get("gear_name", "")).lower()
            play_addr = item.get("play_addr", {})
            if play_addr and play_addr.get("url_list"):
                url = play_addr["url_list"][0]
                if "1080" in gear_name:
                    fhd_url = url
                elif "720" in gear_name:
                    hd_url = url
                    
    return hd_url, fhd_url

async def fetch_tiktok_data(video_id: str) -> dict:
    """Fetch video metadata from TikTok's API endpoints with domain failover."""
    domains = [
        "api22-normal-c-alisg.tiktokv.com",
        "api22-normal-c-useast1a.tiktokv.com",
        "api16-normal-c-useast1a.tiktokv.com"
    ]
    
    last_err = None
    for domain in domains:
        api_url = (
            f"https://{domain}/aweme/v1/feed/?"
            f"aweme_id={video_id}&"
            f"iid=7318518857994389254&"
            f"device_id=7318517321748022790&"
            f"channel=googleplay&"
            f"app_name=musical_ly&"
            f"version_code=300904&"
            f"device_platform=android&"
            f"device_type=SM-ASUS_Z01QD&"
            f"os_version=9"
        )
        try:
            print(f"Trying official Custom API domain: {domain}")
            proxy = await proxy_manager.get_active_proxy()
            client_kwargs = {"timeout": 4.0, "verify": False}
            if proxy:
                client_kwargs["proxy"] = f"http://{proxy}"
            async with httpx.AsyncClient(**client_kwargs) as client:
                response = await client.get(api_url, headers=TIKTOK_HEADERS)
                if response.status_code != 200:
                    raise Exception(f"Status code {response.status_code}")
                
                data = response.json()
                aweme_list = data.get("aweme_list", [])
                if not aweme_list:
                    raise Exception("aweme_list is empty in response")
                    
                print(f"Successfully fetched video details from custom API ({domain})")
                return aweme_list[0]
        except Exception as e:
            print(f"Error calling {domain}: {e}")
            if proxy:
                proxy_manager.invalidate_proxy(proxy)
            last_err = e
            
    # Fallback logic using the free public TikWM API
    print(f"All Custom API domains failed. Falling back to TikWM. Last error: {last_err}")
    fallback_url = f"https://www.tikwm.com/api/?url=https://www.tiktok.com/video/{video_id}&hd=1"
    try:
        import asyncio
        for attempt in range(2):
            async with httpx.AsyncClient(timeout=5.0, verify=False) as client:
                fb_resp = await client.get(fallback_url)
                if fb_resp.status_code == 200:
                    fb_json = fb_resp.json()
                    fb_code = fb_json.get("code")
                    fb_data = fb_json.get("data")
                    if fb_code == 0 and fb_data:
                        # Map TikWM response structure to match expected aweme details
                        return {
                            "is_fallback": True,
                            "desc": fb_data.get("title", ""),
                            "create_time": fb_data.get("create_time", 0),
                            "author": {
                                "unique_id": fb_data.get("author", {}).get("unique_id", ""),
                                "nickname": fb_data.get("author", {}).get("nickname", ""),
                                "uid": str(fb_data.get("author", {}).get("id", ""))
                            },
                            "statistics": {
                                "comment_count": fb_data.get("comment_count", 0),
                                "digg_count": fb_data.get("digg_count", 0),
                                "play_count": fb_data.get("play_count", 0),
                                "share_count": fb_data.get("share_count", 0)
                            },
                            "video": {
                                "cover": {
                                    "url_list": [fb_data.get("cover", "")]
                                },
                                "play_addr": {
                                    "url_list": [fb_data.get("hdplay") or fb_data.get("play", "")]
                                }
                            },
                            "music": {
                                "play_url": {
                                    "url_list": [fb_data.get("music", "")]
                                }
                            }
                        }
                    elif fb_code == -1 and "request/second" in str(fb_json.get("msg", "")):
                        print(f"TikWM rate limited (Attempt {attempt+1}/2). Sleeping 1.5s...")
                        await asyncio.sleep(1.5)
                        continue
            break
    except Exception as fb_err:
        print(f"TikWM fallback error: {fb_err}")
        pass
        
    # Re-raise error if both main API and fallback fail
    raise HTTPException(status_code=400, detail=f"Failed to fetch TikTok video. Last Custom API error: {str(last_err)}")

@app.get("/api/hybrid/video_data")
async def get_video_data(request: Request, url: str = Query(..., description="TikTok or Douyin Video URL")):
    try:
        # 1. Resolve redirect if it's a short URL
        resolved_url = url
        if "vt.tiktok" in url or "vm.tiktok" in url or "v.douyin" in url or "/t/" in url:
            resolved_url = await resolve_url(url)
            
        # 2. Extract video ID
        video_id = extract_video_id(resolved_url)
        if not video_id:
            raise HTTPException(status_code=400, detail="Could not extract video ID from URL")
            
        # 3. Fetch from TikTok API
        aweme_data = await fetch_tiktok_data(video_id)
        
        # 4. Map the data to the format actions/video.php expects
        author_info = aweme_data.get("author", {})
        stats_info = aweme_data.get("statistics", {})
        video_info = aweme_data.get("video", {})
        
        # Get no-watermark video URL
        nwm_url = None
        play_addr = video_info.get("play_addr", {})
        if play_addr and play_addr.get("url_list"):
            nwm_url = play_addr["url_list"][0]
        if not nwm_url:
            download_addr = video_info.get("download_addr", {})
            if download_addr and download_addr.get("url_list"):
                nwm_url = download_addr["url_list"][0]
                
        # Get HD and Full HD URLs
        hd_url, fhd_url = extract_high_quality_urls(video_info)
                
        # Get cover
        cover_url = ""
        cover_obj = video_info.get("cover", {})
        if cover_obj and cover_obj.get("url_list"):
            cover_url = cover_obj["url_list"][0]
            
        # Structure payload to match both Douyin_TikTok_Download_API structure
        mapped_data = {
            "source": "tikwm" if aweme_data.get("is_fallback") else "main_api",
            "type": "video",
            "platform": "tiktok",
            "id": video_id,
            "video_id": video_id,
            "desc": aweme_data.get("desc", ""),
            "create_time": aweme_data.get("create_time", 0),
            "author": {
                "unique_id": author_info.get("unique_id", ""),
                "nickname": author_info.get("nickname", ""),
                "id": str(author_info.get("uid", ""))
            },
            "statistics": {
                "comment_count": stats_info.get("comment_count", 0),
                "digg_count": stats_info.get("digg_count", 0),
                "play_count": stats_info.get("play_count", 0),
                "share_count": stats_info.get("share_count", 0)
            },
            "cover_data": {
                "cover": cover_url
            },
            "video_data": {
                "nwm_video_url": nwm_url,
                "nwm_video_url_HQ": fhd_url or hd_url or nwm_url,
                "nwm_video_url_hd": hd_url,
                "nwm_video_url_fhd": fhd_url,
                "wm_video_url": nwm_url
            }
        }
        
        return {
            "code": 200,
            "router": request.url.path,
            "data": mapped_data
        }
        
    except HTTPException as he:
        return JSONResponse(status_code=he.status_code, content={"code": he.status_code, "msg": he.detail})
    except Exception as e:
        return JSONResponse(status_code=500, content={"code": 500, "msg": str(e)})

async def fetch_tiktok_comments(video_id: str, count: int = 50, cursor: str = "0") -> dict:
    """Fetch one page of comments of a TikTok video with pagination using domain failover."""
    comments = []
    has_more = False
    next_cursor = "0"
    
    domains = [
        "api22-normal-c-alisg.tiktokv.com",
        "api22-normal-c-useast1a.tiktokv.com"
    ]
    
    for domain in domains:
        api_url = (
            f"https://{domain}/aweme/v1/comment/list/?"
            f"aweme_id={video_id}&"
            f"cursor={cursor}&"
            f"count={count}&"
            f"device_type=SM-ASUS_Z01QD&"
            f"device_platform=android&"
            f"iid=7318518857994389254&"
            f"device_id=7318517321748022790&"
            f"version_code=300904&"
            f"app_name=musical_ly"
        )
        try:
            print(f"Trying official comments API: {domain}")
            proxy = await proxy_manager.get_active_proxy()
            client_kwargs = {"timeout": 5.0, "verify": False}
            if proxy:
                client_kwargs["proxy"] = f"http://{proxy}"
            async with httpx.AsyncClient(**client_kwargs) as client:
                response = await client.get(api_url, headers=TIKTOK_HEADERS)
                if response.status_code == 200:
                    if not response.text or len(response.text.strip()) == 0:
                        raise Exception("Response body is empty")
                    data = response.json()
                    comments_list = data.get("comments", [])
                    if comments_list:
                        for c in comments_list:
                            user_info = c.get("user", {})
                            comments.append({
                                "comment_id": str(c.get("cid", "")),
                                "text": c.get("text", ""),
                                "create_time": c.get("create_time", 0),
                                "digg_count": c.get("digg_count", 0),
                                "reply_comment_total": c.get("reply_comment_total", 0),
                                "author": {
                                    "unique_id": user_info.get("unique_id", ""),
                                    "nickname": user_info.get("nickname", ""),
                                    "avatar": user_info.get("avatar_thumb", {}).get("url_list", [""])[0]
                                }
                            })
                        has_more = data.get("has_more", 0) == 1
                        next_cursor = str(data.get("cursor", "0"))
                        print(f"Successfully fetched comments from {domain}")
                        return {"comments": comments, "cursor": next_cursor, "has_more": has_more}
        except Exception as e:
            print(f"Error fetching comments from {domain}: {e}")
            if proxy:
                proxy_manager.invalidate_proxy(proxy)
            
    # Fallback to TikWM API if the official API is empty or fails
    print("All official comments domains failed or returned empty. Falling back to TikWM.")
    tikwm_url = f"https://www.tikwm.com/api/comment/list?url=https://www.tiktok.com/video/{video_id}&count={count}&cursor={cursor}"
    try:
        async with httpx.AsyncClient(timeout=6.0, verify=False) as client:
            response = await client.get(tikwm_url)
            if response.status_code == 200:
                data = response.json()
                if data.get("code") == 0 and data.get("data"):
                    data_obj = data["data"]
                    comments_list = data_obj.get("comments", [])
                    if comments_list:
                        for c in comments_list:
                            user_info = c.get("user", {})
                            comments.append({
                                "comment_id": str(c.get("id", c.get("cid", ""))),
                                "text": c.get("text", ""),
                                "create_time": c.get("create_time", 0),
                                "digg_count": c.get("digg_count", 0),
                                "reply_comment_total": c.get("reply_total", c.get("reply_comment_total", 0)),
                                "author": {
                                    "unique_id": user_info.get("unique_id", ""),
                                    "nickname": user_info.get("nickname", ""),
                                    "avatar": user_info.get("avatar", user_info.get("avatar_thumb", {}).get("url_list", [""])[0])
                                },
                                "is_fallback": True
                            })
                        has_more = data_obj.get("hasMore", data_obj.get("has_more", False))
                        next_cursor = str(data_obj.get("cursor", "0"))
                        return {"comments": comments, "cursor": next_cursor, "has_more": has_more}
    except Exception as e:
        print(f"Error fetching comments from TikWM fallback: {e}")
        
    return {"comments": [], "cursor": "0", "has_more": False}

async def fetch_sec_uid_from_username(username: str) -> str:
    """Fetch sec_user_id from username using official profile endpoints with TikWM fallback."""
    username = username.lstrip("@").strip()
    
    domains = [
        "api22-normal-c-alisg.tiktokv.com",
        "api22-normal-c-useast1a.tiktokv.com"
    ]
    
    for domain in domains:
        api_url = (
            f"https://{domain}/aweme/v1/user/profile/other/?"
            f"unique_id={username}&"
            f"device_type=SM-ASUS_Z01QD&"
            f"device_platform=android&"
            f"iid=7318518857994389254&"
            f"device_id=7318517321748022790&"
            f"version_code=300904&"
            f"app_name=musical_ly"
        )
        try:
            print(f"Trying official profile API: {domain}")
            proxy = await proxy_manager.get_active_proxy()
            client_kwargs = {"timeout": 5.0, "verify": False}
            if proxy:
                client_kwargs["proxy"] = f"http://{proxy}"
            async with httpx.AsyncClient(**client_kwargs) as client:
                response = await client.get(api_url, headers=TIKTOK_HEADERS)
                if response.status_code == 200:
                    if not response.text or len(response.text.strip()) == 0:
                        raise Exception("Response body is empty")
                    data = response.json()
                    user_info = data.get("user", {})
                    sec_uid = user_info.get("sec_uid", "")
                    if sec_uid:
                        print(f"Successfully fetched sec_uid from {domain}: {sec_uid}")
                        return sec_uid
        except Exception as e:
            print(f"Error fetching profile from {domain}: {e}")
            if proxy:
                proxy_manager.invalidate_proxy(proxy)
            
    # Fallback to TikWM API
    print("All official profile domains failed or returned empty. Falling back to TikWM.")
    tikwm_url = f"https://www.tikwm.com/api/user/info?unique_id={username}"
    try:
        async with httpx.AsyncClient(timeout=6.0, verify=False) as client:
            response = await client.get(tikwm_url)
            if response.status_code == 200:
                data = response.json()
                if data.get("code") == 0 and data.get("data"):
                    user_obj = data["data"].get("user", {})
                    sec_uid = user_obj.get("secUid", "")
                    if sec_uid:
                        return sec_uid
    except Exception as e:
        print(f"Error fetching sec_uid from TikWM fallback: {e}")
        
    return ""

async def fetch_tiktok_user_videos(sec_uid: str, max_count: int = 33, unique_id: str = "", cursor: str = "0") -> dict:
    """Fetch one page of posts/videos of a TikTok user with pagination cursor using domain failover."""
    videos = []
    has_more = False
    next_cursor = 0
    
    if unique_id:
        unique_id = unique_id.lstrip("@").strip()
        
    domains = [
        "api22-normal-c-alisg.tiktokv.com",
        "api22-normal-c-useast1a.tiktokv.com"
    ]
    
    for domain in domains:
        api_url = (
            f"https://{domain}/aweme/v1/aweme/post/?"
            f"sec_user_id={sec_uid}&"
            f"cursor={cursor}&"
            f"count={max_count}&"
            f"device_type=SM-ASUS_Z01QD&"
            f"device_platform=android&"
            f"iid=7318518857994389254&"
            f"device_id=7318517321748022790&"
            f"version_code=300904&"
            f"app_name=musical_ly"
        )
        try:
            print(f"Trying official user posts API: {domain}")
            proxy = await proxy_manager.get_active_proxy()
            client_kwargs = {"timeout": 6.0, "verify": False}
            if proxy:
                client_kwargs["proxy"] = f"http://{proxy}"
            async with httpx.AsyncClient(**client_kwargs) as client:
                response = await client.get(api_url, headers=TIKTOK_HEADERS)
                if response.status_code == 200:
                    if not response.text or len(response.text.strip()) == 0:
                        raise Exception("Response body is empty")
                    data = response.json()
                    aweme_list = data.get("aweme_list", [])
                    if aweme_list:
                        for item in aweme_list:
                            video_id = item.get("aweme_id", "")
                            desc = item.get("desc", "")
                            create_time = item.get("create_time", 0)
                            
                            video_info = item.get("video", {})
                            cover_url = video_info.get("cover", {}).get("url_list", [""])[0]
                            
                            nwm_url = None
                            play_addr = video_info.get("play_addr", {})
                            if play_addr and play_addr.get("url_list"):
                                nwm_url = play_addr["url_list"][0]
                            if not nwm_url:
                                download_addr = video_info.get("download_addr", {})
                                if download_addr and download_addr.get("url_list"):
                                    nwm_url = download_addr["url_list"][0]
                                    
                            stats = item.get("statistics", {})
                            region = item.get("region", "VN")
                            duration = int(video_info.get("duration", 0) / 1000)
                            
                            videos.append({
                                "video_id": video_id,
                                "desc": desc,
                                "create_time": create_time,
                                "cover": cover_url,
                                "nwm_video_url": nwm_url,
                                "region": region,
                                "duration": duration,
                                "statistics": {
                                    "comment_count": stats.get("comment_count", 0),
                                    "digg_count": stats.get("digg_count", 0),
                                    "play_count": stats.get("play_count", 0),
                                    "share_count": stats.get("share_count", 0)
                                }
                            })
                        has_more = data.get("has_more", 0) == 1
                        next_cursor = data.get("max_cursor", 0)
                        print(f"Successfully fetched user videos from {domain}")
                        return {"videos": videos, "cursor": next_cursor, "has_more": has_more}
        except Exception as e:
            print(f"Error fetching user videos from {domain}: {e}")
            if proxy:
                proxy_manager.invalidate_proxy(proxy)
            
    # Fallback to TikWM API
    print("All official posts domains failed or returned empty. Falling back to TikWM.")
    if unique_id:
        tikwm_url = f"https://www.tikwm.com/api/user/posts?unique_id={unique_id}&count={max_count}&cursor={cursor}"
    else:
        tikwm_url = f"https://www.tikwm.com/api/user/posts?sec_user_id={sec_uid}&count={max_count}&cursor={cursor}"
        
    try:
        async with httpx.AsyncClient(timeout=6.0, verify=False) as client:
            response = await client.get(tikwm_url)
            if response.status_code == 200:
                data = response.json()
                if data.get("code") == 0 and data.get("data"):
                    data_obj = data["data"]
                    videos_list = data_obj.get("videos", [])
                    if videos_list:
                        for item in videos_list:
                            video_id = item.get("video_id", "")
                            desc = item.get("title", "")
                            create_time = item.get("create_time", 0)
                            cover_url = item.get("cover", "")
                            nwm_url = item.get("play", "")
                            region = item.get("region", "VN")
                            duration = int(item.get("duration", 0))
                            videos.append({
                                "video_id": video_id,
                                "desc": desc,
                                "create_time": create_time,
                                "cover": cover_url,
                                "nwm_video_url": nwm_url,
                                "region": region,
                                "duration": duration,
                                "statistics": {
                                    "comment_count": item.get("comment_count", 0),
                                    "digg_count": item.get("digg_count", 0),
                                    "play_count": item.get("play_count", 0),
                                    "share_count": item.get("share_count", 0)
                                },
                                "is_fallback": True
                            })
                        has_more = data_obj.get("hasMore", data_obj.get("has_more", False))
                        next_cursor = data_obj.get("cursor", 0)
                        return {"videos": videos, "cursor": next_cursor, "has_more": has_more}
    except Exception as e:
        print(f"Error fetching user videos from TikWM fallback: {e}")
        
    return {"videos": [], "cursor": 0, "has_more": False}

@app.get("/api/tiktok/comments")
async def get_comments(
    url: str = Query(None, description="TikTok Video URL"),
    video_id: str = Query(None, description="TikTok Video ID"),
    count: int = Query(50, description="Number of comments to fetch per page."),
    cursor: str = Query("0", description="Pagination cursor.")
):
    target_video_id = video_id
    if not target_video_id and url:
        resolved_url = url
        if "vt.tiktok" in url or "vm.tiktok" in url or "/t/" in url:
            resolved_url = await resolve_url(url)
        target_video_id = extract_video_id(resolved_url)
        
    if not target_video_id:
        raise HTTPException(status_code=400, detail="Could not extract video ID from parameters")
        
    result = await fetch_tiktok_comments(target_video_id, count, cursor)
    return {
        "code": 200,
        "video_id": target_video_id,
        "cursor": result["cursor"],
        "has_more": result["has_more"],
        "comments": result["comments"]
    }

@app.get("/api/tiktok/user_videos")
async def get_user_videos(
    username: str = Query(None, description="TikTok Username (e.g., @copphavietcom)"),
    sec_uid: str = Query(None, description="TikTok sec_user_id"),
    count: int = Query(33, description="Number of videos to fetch per page."),
    cursor: str = Query("0", description="Pagination cursor.")
):
    target_sec_uid = sec_uid
    parsed_username = ""
    if username:
        parsed_username = username.lstrip("@").strip()
        if "tiktok.com/" in username:
            match = re.search(r"@([a-zA-Z0-9_\.]+)", username)
            if match:
                parsed_username = match.group(1)
                
    if not target_sec_uid and parsed_username:
        target_sec_uid = await fetch_sec_uid_from_username(parsed_username)
        
    if not target_sec_uid:
        raise HTTPException(status_code=400, detail="Could not resolve sec_user_id for this user")
        
    result = await fetch_tiktok_user_videos(target_sec_uid, count, parsed_username, cursor)
    return {
        "code": 200,
        "sec_user_id": target_sec_uid,
        "cursor": result["cursor"],
        "has_more": result["has_more"],
        "videos": result["videos"]
    }

if __name__ == "__main__":
    uvicorn.run("api:app", host="127.0.0.1", port=8000, reload=False)
