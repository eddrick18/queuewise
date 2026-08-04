import axios from "axios";

const api = axios.create({
  baseURL: "http://localhost:8000",

  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
  },

  // Allow Laravel's authentication cookie to be sent.
  withCredentials: true,

  // Send Laravel's XSRF token automatically.
  withXSRFToken: true,
});

export default api;