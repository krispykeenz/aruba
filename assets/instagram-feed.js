(function () {
  var section = document.querySelector("[data-instagram-feed]");

  if (!section || !window.fetch) {
    return;
  }

  var endpoint = section.getAttribute("data-endpoint") || "api/instagram-feed.php";
  var fallback = section.querySelector("[data-instagram-fallback]");
  var live = section.querySelector("[data-instagram-live]");
  var grid = section.querySelector("[data-instagram-grid]");
  var profileLinks = section.querySelectorAll("[data-instagram-profile-link]");

  if (!fallback || !live || !grid) {
    return;
  }

  fetch(endpoint, {
    headers: {
      Accept: "application/json"
    },
    cache: "no-store"
  })
    .then(function (response) {
      if (!response.ok) {
        throw new Error("Instagram feed unavailable");
      }

      return response.json();
    })
    .then(function (data) {
      var items = Array.isArray(data.items) ? data.items.slice(0, 6) : [];

      if (!items.length) {
        throw new Error("Instagram feed empty");
      }

      renderFeed(data, items);
    })
    .catch(showFallback);

  function renderFeed(data, items) {
    var profileUrl = safeUrl(data.profileUrl, section.getAttribute("data-profile-url") || fallback.href);

    grid.textContent = "";

    profileLinks.forEach(function (link) {
      link.href = profileUrl;
    });

    items.forEach(function (item) {
      var imageUrl = safeImageUrl(item.imageUrl);

      if (!imageUrl) {
        return;
      }

      var link = document.createElement("a");
      var image = document.createElement("img");

      link.className = "instagram-post";
      link.href = safeUrl(item.permalink, profileUrl);
      link.target = "_blank";
      link.rel = "noopener noreferrer";
      link.setAttribute("aria-label", "View Aruba Instagram post");
      link.setAttribute("data-type", String(item.type || "IMAGE"));

      image.src = imageUrl;
      image.alt = truncate(item.caption || "Aruba Instagram post", 125);
      image.loading = "lazy";
      image.decoding = "async";
      image.addEventListener("error", function () {
        link.remove();

        if (!grid.children.length) {
          showFallback();
        }
      });

      link.appendChild(image);
      grid.appendChild(link);
    });

    if (!grid.children.length) {
      throw new Error("Instagram feed had no renderable items");
    }

    fallback.hidden = true;
    live.hidden = false;
  }

  function showFallback() {
    fallback.hidden = false;
    live.hidden = true;
  }

  function safeUrl(value, fallbackUrl) {
    try {
      var url = new URL(value || "", window.location.href);

      if (url.protocol === "https:" || url.protocol === "http:") {
        return url.href;
      }
    } catch (error) {
      return fallbackUrl;
    }

    return fallbackUrl;
  }

  function safeImageUrl(value) {
    try {
      var url = new URL(value || "", window.location.href);

      if (url.protocol === "https:") {
        return url.href;
      }
    } catch (error) {
      return "";
    }

    return "";
  }

  function truncate(value, maxLength) {
    value = String(value).trim();

    if (value.length <= maxLength) {
      return value;
    }

    return value.slice(0, maxLength - 1) + "...";
  }
})();
