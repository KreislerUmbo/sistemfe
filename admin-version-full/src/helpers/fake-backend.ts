import axios from "axios";
import MockAdapter from "axios-mock-adapter";
import type { User } from "@/types/auth";

const mock = new MockAdapter(axios);

export const fakeUsers: User[] = [
  {
    id: "1",
    email: "admin@email.com",
    username: "admin",
    password: "password",
    firstName: "Admin",
    lastName: "Techzaa",
    role: "admin",
    token:
      "eyJhbGciOiJIUzUxMiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJjb2RlcnRoZW1lcyIsImF1ZCI6Imh0dHBzOi8vY29kZXJ0aGVtZXMuY29tLyIsInN1YiI6InN1cHBvcnRAY29kZXJ0aGVtZXMuY29tIiwibGFzdE5hbWUiOiJDb2RlcnRoZW1lcyIsIkVtYWlsIjoic3VwcG9ydEBjb2RlcnRoZW1lcy5jb20iLCJSb2xlIjoiQWRtaW4iLCJmaXJzdE5hbWUiOiJBcm91bmQifQ.yTJpBUmQ_T1dUf-dIdgxXn16KZjhvXw6cUT1sFhnOtKJ3-67gnRCC41nNVGLJpyjv4HCLVj0ytTbmfWzWcY_2Q",
  },
  {
    id: "2",
    email: "user@email.com",
    username: "user",
    password: "password",
    firstName: "User",
    lastName: "Techzaa",
    role: "user",
    token:
      "eyJhbGciOiJIUzUxMiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJjb2RlcnRoZW1lcyIsImF1ZCI6Imh0dHBzOi8vY29kZXJ0aGVtZXMuY29tLyIsInN1YiI6InN1cHBvcnRAY29kZXJ0aGVtZXMuY29tIiwibGFzdE5hbWUiOiJDb2RlcnRoZW1lcyIsIkVtYWlsIjoic3VwcG9ydEBjb2RlcnRoZW1lcy5jb20iLCJSb2xlIjoiQWRtaW4iLCJmaXJzdE5hbWUiOiJBcm91bmQifQ.yTJpBUmQ_T1dUf-dIdgxXn16KZjhvXw6cUT1sFhnOtKJ3-67gnRCC41nNVGLJpyjv4HCLVj0ytTbmfWzWcY_2Q",
  },
];

export default function configureFakeBackend() {
  mock.onPost("/sign-in").reply(function (config) {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    return new Promise(function (resolve, _reject) {
      setTimeout(function () {
        // get parameters from post request
        const params = JSON.parse(config.data);
        // find if any user matches login credentials
        const filteredUsers = fakeUsers.filter((user) => {
          return (
            user.email === params.email && user.password === params.password
          );
        });

        if (filteredUsers.length) {
          // if login details are valid return user details and fake jwt token
          const { id, email, username, firstName, lastName, role, token } =
            filteredUsers[0];
          resolve([
            200,
            { id, email, username, firstName, lastName, role, token },
          ]);
        } else {
          // else return error
          resolve([401, { error: "Username or password is incorrect" }]);
        }
      }, 1000);
    });
  });
}
