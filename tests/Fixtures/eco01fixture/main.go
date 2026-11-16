package main

import (
	"fmt"
	"os"
	"strings"
	"time"
)

func main() {
	if len(os.Args) < 2 || os.Args[1] != "version" {
		os.Exit(2)
	}

	mode := "ok"
	raw, err := os.ReadFile(os.Args[0] + ".mode")
	if err == nil {
		mode = strings.TrimSpace(string(raw))
	}

	switch mode {
	case "hang":
		for {
			time.Sleep(time.Hour)
		}
	case "badexit":
		fmt.Println("eregion 0.4.2")
		fmt.Println("protocol eregion/1")
		os.Exit(3)
	case "badproto":
		fmt.Println("eregion 0.4.2")
		fmt.Println("protocol eregion/9")
	case "malformed":
		fmt.Println("not-a-version")
	case "withgo":
		fmt.Println("eregion 0.4.2")
		fmt.Println("protocol eregion/1")
		fmt.Println("go go1.23.12 linux/amd64")
	case "extra":
		fmt.Println("eregion 0.4.2")
		fmt.Println("protocol eregion/1")
		fmt.Println("build dirty")
	case "old":
		fmt.Println("eregion 0.1.0")
		fmt.Println("protocol eregion/1")
	default:
		fmt.Println("eregion 0.4.2")
		fmt.Println("protocol eregion/1")
	}
}
