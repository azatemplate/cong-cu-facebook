import re
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

async def resolve_url(url: str) -> str:
    """Resolve short URLs and redirects to get the final TikTok URL."""
    async with httpx.AsyncClient(follow_redirects=True, timeout=3.0, verify=False) as client:
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

async def fetch_tiktok_data(video_id: str) -> dict:
    """Fetch video metadata from TikTok's SG API endpoint (fast and stable from Asia)."""
    # Use SG api22-normal-c-alisg domain (much faster than US domains for Asian VPS)
    api_url = (
        f"https://api22-normal-c-alisg.tiktokv.com/aweme/v1/feed/?"
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
        async with httpx.AsyncClient(timeout=3.0, verify=False) as client:
            response = await client.get(api_url, headers=TIKTOK_HEADERS)
            if response.status_code != 200:
                raise Exception(f"TikTok API responded with status {response.status_code}")
            
            data = response.json()
            aweme_list = data.get("aweme_list", [])
            if not aweme_list:
                raise Exception("Video details not found in TikTok response")
                
            return aweme_list[0]
            
    except Exception as e:
        print(f"Error fetching tiktok data from Alisg: {e}")
        # Fallback logic using the free public TikWM API
        fallback_url = f"https://www.tikwm.com/api/?url=https://www.tiktok.com/video/{video_id}"
        try:
            async with httpx.AsyncClient(timeout=5.0, verify=False) as client:
                fb_resp = await client.get(fallback_url)
                if fb_resp.status_code == 200:
                    fb_json = fb_resp.json()
                    fb_data = fb_json.get("data")
                    if fb_json.get("code") == 0 and fb_data:
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
                                    "url_list": [fb_data.get("play", "")]
                                }
                            }
                        }
        except Exception as fb_err:
            pass
            
        # Re-raise error if both main API and fallback fail
        raise HTTPException(status_code=400, detail=f"Failed to fetch TikTok video. Error: {str(e)}")

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
                "nwm_video_url_HQ": nwm_url,
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

async def fetch_tiktok_comments(video_id: str, max_count: int = 50) -> list:
    """Fetch comments of a TikTok video with pagination."""
    comments = []
    cursor = 0
    page_size = 50
    has_more = True
    loop_limit = 40  # Maximum 40 requests (approx. 2000 comments) to prevent IP throttling
    loop_count = 0
    
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36",
        "Referer": "https://www.tiktok.com/"
    }
    
    while has_more and loop_count < loop_limit:
        if max_count > 0 and len(comments) >= max_count:
            break
            
        fetch_size = page_size
        if max_count > 0:
            fetch_size = min(page_size, max_count - len(comments))
            
        api_url = (
            f"https://api22-normal-c-alisg.tiktokv.com/aweme/v1/comment/list/?"
            f"aweme_id={video_id}&"
            f"cursor={cursor}&"
            f"count={fetch_size}&"
            f"device_type=SM-ASUS_Z01QD&"
            f"device_platform=android&"
            f"iid=7318518857994389254&"
            f"device_id=7318517321748022790&"
            f"version_code=300904&"
            f"app_name=musical_ly"
        )
        
        try:
            async with httpx.AsyncClient(timeout=5.0, verify=False) as client:
                response = await client.get(api_url, headers=headers)
                if response.status_code != 200:
                    print(f"Alisg comments responded with status {response.status_code}")
                    break
                data = response.json()
                
                comments_list = data.get("comments", [])
                if not comments_list:
                    break
                    
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
                cursor = data.get("cursor", 0)
                
                if cursor == 0 or not has_more:
                    break
                    
        except Exception as e:
            print(f"Error fetching comments from Alisg: {e}")
            break
            
        loop_count += 1
        
    if not comments:
        # Fallback to TikWM API if the official API is empty (due to protection blocks)
        cursor = 0
        has_more = True
        loop_limit = 40
        loop_count = 0
        while has_more and loop_count < loop_limit:
            if max_count > 0 and len(comments) >= max_count:
                break
            fetch_size = page_size
            if max_count > 0:
                fetch_size = min(page_size, max_count - len(comments))
            
            tikwm_url = f"https://www.tikwm.com/api/comment/list?url=https://www.tiktok.com/video/{video_id}&count={fetch_size}&cursor={cursor}"
            try:
                async with httpx.AsyncClient(timeout=6.0, verify=False) as client:
                    response = await client.get(tikwm_url)
                    if response.status_code != 200:
                        print(f"TikWM comments responded with status {response.status_code}")
                        break
                    data = response.json()
                    if data.get("code") != 0 or not data.get("data"):
                        break
                    data_obj = data["data"]
                    comments_list = data_obj.get("comments", [])
                    if not comments_list:
                        break
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
                    cursor = data_obj.get("cursor", 0)
                    if cursor == 0 or not has_more:
                        break
            except Exception as e:
                print(f"Error fetching comments from TikWM: {e}")
                break
            loop_count += 1
            
    return comments[:max_count] if max_count > 0 else comments

async def fetch_sec_uid_from_username(username: str) -> str:
    """Fetch sec_user_id from username using Alisg profile endpoint with TikWM fallback."""
    username = username.lstrip("@").strip()
    
    api_url = (
        f"https://api22-normal-c-alisg.tiktokv.com/aweme/v1/user/profile/other/?"
        f"unique_id={username}&"
        f"device_type=SM-ASUS_Z01QD&"
        f"device_platform=android&"
        f"iid=7318518857994389254&"
        f"device_id=7318517321748022790&"
        f"version_code=300904&"
        f"app_name=musical_ly"
    )
    
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36",
        "Referer": "https://www.tiktok.com/"
    }
    
    try:
        async with httpx.AsyncClient(timeout=5.0, verify=False) as client:
            response = await client.get(api_url, headers=headers)
            if response.status_code == 200:
                data = response.json()
                user_info = data.get("user", {})
                sec_uid = user_info.get("sec_uid", "")
                if sec_uid:
                    return sec_uid
            else:
                print(f"Alisg profile responded with status {response.status_code}")
    except Exception as e:
        print(f"Error fetching sec_uid from Alisg: {e}")
        
    # Fallback to TikWM API
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
            else:
                print(f"TikWM user/info responded with status {response.status_code}")
    except Exception as e:
        print(f"Error fetching sec_uid from TikWM: {e}")
        
    return ""

async def fetch_tiktok_user_videos(sec_uid: str, max_count: int = 33, unique_id: str = "", cursor: int = 0) -> dict:
    """Fetch one page of posts/videos of a TikTok user with pagination cursor."""
    videos = []
    has_more = False
    next_cursor = 0
    
    if unique_id:
        unique_id = unique_id.lstrip("@").strip()
        
    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/90.0.4430.212 Safari/537.36",
        "Referer": "https://www.tiktok.com/"
    }
    
    # Try official Alisg API first
    api_url = (
        f"https://api22-normal-c-alisg.tiktokv.com/aweme/v1/aweme/post/?"
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
        async with httpx.AsyncClient(timeout=6.0, verify=False) as client:
            response = await client.get(api_url, headers=headers)
            if response.status_code == 200:
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
                    return {"videos": videos, "cursor": next_cursor, "has_more": has_more}
    except Exception as e:
        print(f"Error fetching user videos from Alisg: {e}")
        
    # Fallback to TikWM API
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
        print(f"Error fetching user videos from TikWM: {e}")
        
    return {"videos": [], "cursor": 0, "has_more": False}

@app.get("/api/tiktok/comments")
async def get_comments(
    url: str = Query(None, description="TikTok Video URL"),
    video_id: str = Query(None, description="TikTok Video ID"),
    count: str = Query("50", description="Number of comments to fetch. Use 'all' or '0' to fetch all.")
):
    max_count = 50
    if count.lower() == "all" or count == "0":
        max_count = 0
    elif count.isdigit():
        max_count = int(count)
        
    target_video_id = video_id
    if not target_video_id and url:
        resolved_url = url
        if "vt.tiktok" in url or "vm.tiktok" in url or "/t/" in url:
            resolved_url = await resolve_url(url)
        target_video_id = extract_video_id(resolved_url)
        
    if not target_video_id:
        raise HTTPException(status_code=400, detail="Could not extract video ID from parameters")
        
    comments_data = await fetch_tiktok_comments(target_video_id, max_count)
    return {
        "code": 200,
        "video_id": target_video_id,
        "total_fetched": len(comments_data),
        "comments": comments_data
    }

@app.get("/api/tiktok/user_videos")
async def get_user_videos(
    username: str = Query(None, description="TikTok Username (e.g., @copphavietcom)"),
    sec_uid: str = Query(None, description="TikTok sec_user_id"),
    count: int = Query(33, description="Number of videos to fetch per page."),
    cursor: int = Query(0, description="Pagination cursor.")
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
